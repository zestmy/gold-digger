<?php

namespace App\Services\Ai;

use App\Models\BotHeartbeat;
use App\Models\BotSettings;
use App\Models\Candle;
use App\Models\Signal;
use App\Models\Strategy;
use App\Services\Backtest\MarketAssumptions;
use App\Services\Backtest\WalkForward;
use App\Services\Backtest\WalkForwardReport;
use App\Services\MarketData\MarketData;

/**
 * Strategy Improvement
 *
 * Propose candidate parameters, then measure them. The logic behind both the
 * `strategy:improve` command and the queued job the dashboard dispatches.
 *
 * It lives here rather than in the command because the two callers must not drift. A
 * console run and a dashboard run that disagreed about the baseline, the bar window, or
 * the thin-sample threshold would produce two different answers to the same question, and
 * the one you would believe is whichever you saw last.
 *
 * The division of labour is unchanged and is the point: the model proposes, WalkForward
 * decides, and nothing here writes a parameter to a strategy.
 */
final class StrategyImprovement
{
    /**
     * Bars to read when no window is given.
     *
     * Not "all of them". The droplet this runs on has under 200MB free and a walk-forward
     * holds every bar in memory as a model - 60,000 of them is roughly 400MB and an OOM
     * that takes the trading dashboard down with it. A bounded default is the difference
     * between a slow answer and no dashboard.
     */
    public const DEFAULT_BARS = 20000;

    public function __construct(
        private readonly StrategyProposer $proposer = new StrategyProposer,
        private readonly WalkForward $walkForward = new WalkForward,
    ) {}

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function run(Strategy $strategy, array $options = [], ?callable $onProgress = null): array
    {
        if (! $this->proposer->configured()) {
            return $this->failure('No OPENROUTER_API_KEY is configured, so nothing can be proposed.');
        }

        $heartbeat = BotHeartbeat::where('user_id', $strategy->user_id)->orderByDesc('last_seen_at')->first();
        $accountId = $options['account'] ?? $heartbeat?->broker_account_id;
        $symbol = $options['symbol'] ?: ($heartbeat?->resolved_symbol ?: $strategy->symbol);
        $bars = (int) ($options['bars'] ?? self::DEFAULT_BARS);

        $entry = $this->candles($accountId, $symbol, $strategy->timeframe_entry, $bars, $options);
        $trend = $this->candles($accountId, $symbol, $strategy->timeframe_trend, $bars, $options);

        if ($entry === []) {
            return $this->failure("No {$strategy->timeframe_entry} candles stored for {$symbol}.");
        }

        $market = MarketAssumptions::fromHeartbeat($heartbeat, array_filter([
            'spreadPips' => $options['spread'] ?? null,
            'slippagePips' => $options['slippage'] ?? 0.3,
            'commissionPerLot' => $options['commission'] ?? 0.0,
            'startingBalance' => $options['balance'] ?? 1000.0,
        ], fn ($v) => $v !== null));

        $settings = BotSettings::where('user_id', $strategy->user_id)->first();
        $folds = (int) ($options['folds'] ?? 4);
        $minTrades = (int) ($options['min_trades'] ?? 10);

        // The baseline first. "Expectancy +2.34" answers nothing on its own; the only
        // question worth asking is whether a change is an improvement on what runs today.
        $baselineReport = $this->walkForward->run(
            $strategy, $entry, $trend, [[]], $market, $settings, $folds, $minTrades,
        );
        $baseline = $baselineReport->outOfSample();

        $proposed = $this->proposer->propose($strategy, [
            'data_range' => sprintf(
                '%s bars of %s from %s to %s',
                count($entry),
                $strategy->timeframe_entry,
                $entry[0]->open_time->format('Y-m-d'),
                $entry[count($entry) - 1]->open_time->format('Y-m-d'),
            ),
            'baseline' => $this->describe($baseline),
            'skip_reasons' => $this->skipReasons($strategy),
        ]);

        if (! $proposed['ok']) {
            return $this->failure($proposed['error'] ?? 'The proposer failed.');
        }

        $report = $this->walkForward->run(
            $strategy,
            $entry,
            $trend,
            array_map(fn (array $p) => $p['parameters'], $proposed['proposals']),
            $market,
            $settings,
            $folds,
            $minTrades,
            $onProgress,
        );

        $oos = $report->outOfSample();
        $thin = ($oos['trades'] ?? 0) < WalkForwardReport::MIN_MEANINGFUL_TRADES;

        return [
            'ok' => true,
            'error' => null,
            'symbol' => $symbol,
            'bars' => count($entry),
            'from' => $entry[0]->open_time->toDateString(),
            'to' => $entry[count($entry) - 1]->open_time->toDateString(),
            'baseline' => $baseline,
            'proposed' => $oos,
            'proposals' => $proposed['proposals'],
            'model' => $proposed['model'],
            'notes' => $report->notes,
            // The verdict travels with the numbers rather than being recomputed by each
            // caller, so a UI cannot render the table and quietly omit the warning.
            'thin' => $thin,
            'verdict' => $report->degradation()['verdict'],
            'baseline_summary' => $this->describe($baseline),
            'proposed_summary' => $this->describe($oos),
        ];
    }

    /**
     * @param  array<string, mixed>  $oos
     */
    public function describe(array $oos): string
    {
        if (($oos['trades'] ?? 0) === 0) {
            return 'no out-of-sample trades - not enough history, or the entry rule is too selective';
        }

        return sprintf(
            '%d trades, net %s, %s%% wins, expectancy %s, %d of %d folds profitable',
            $oos['trades'],
            $this->signed((float) $oos['net_pnl']),
            $oos['win_rate'],
            $this->signed((float) $oos['expectancy']),
            $oos['folds_profitable'] ?? 0,
            $oos['folds_tested'] ?? 0,
        );
    }

    private function signed(float $value): string
    {
        return ($value >= 0 ? '+' : '').number_format($value, 2);
    }

    /**
     * What actually stopped recent setups - the most useful thing the model is given.
     *
     * @return array<string, int>
     */
    private function skipReasons(Strategy $strategy): array
    {
        return Signal::where('strategy_id', $strategy->id)
            ->selectRaw('coalesce(skip_reason, ?) as reason, count(*) as total', ['traded'])
            ->groupBy('reason')
            ->orderByDesc('total')
            ->pluck('total', 'reason')
            ->all();
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<int, Candle>
     */
    private function candles(?int $accountId, string $symbol, string $timeframe, int $limit, array $options): array
    {
        // A date range is a question about specific bars, so it is answered from what is
        // stored rather than from a vendor - "what happened in March" and "twenty thousand
        // bars from somewhere" are different requests.
        $ranged = ($options['from'] ?? null) !== null || ($options['to'] ?? null) !== null;

        if (! $ranged) {
            // This is the only consumer in the application that asks for 20,000 bars; the
            // next deepest wants 300. Reading them on demand rather than keeping them is
            // what lets the stored series shrink to what trading needs.
            $deep = app(MarketData::class)->forBacktest($symbol, $timeframe, $limit, $accountId);

            if ($deep['bars'] !== []) {
                return $deep['bars'];
            }
        }

        // Newest-first with a limit, then reversed: taking the oldest N of a long series
        // would measure last autumn and ignore everything since.
        return Candle::query()
            ->when($accountId !== null, fn ($q) => $q->where('broker_account_id', $accountId))
            ->where('symbol', $symbol)
            ->where('timeframe', strtoupper($timeframe))
            ->when($options['from'] ?? null, fn ($q, $from) => $q->where('open_time', '>=', $from))
            ->when($options['to'] ?? null, fn ($q, $to) => $q->where('open_time', '<=', $to))
            ->orderByDesc('open_time')
            ->limit($limit)
            ->get()
            ->reverse()
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function failure(string $message): array
    {
        return ['ok' => false, 'error' => $message];
    }
}
