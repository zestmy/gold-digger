<?php

namespace App\Console\Commands;

use App\Models\BotHeartbeat;
use App\Models\BotSettings;
use App\Models\Candle;
use App\Models\Signal;
use App\Models\Strategy;
use App\Services\Ai\StrategyProposer;
use App\Services\Backtest\MarketAssumptions;
use App\Services\Backtest\WalkForward;
use Illuminate\Console\Command;

/**
 * Ask a model for candidate parameters, then measure them.
 *
 * ## The division of labour
 *
 * `backtest:optimise` searches a grid you specify. That is exhaustive within the grid and
 * completely blind about which grid to search - it will happily spend a thousand runs on
 * ATR periods while every recent setup dies on ADX.
 *
 * This asks a model where to look, and then submits the answer to exactly the same
 * walk-forward validation. The model never sees a result and never gets a vote. What comes
 * out is a table of measured out-of-sample performance with a sentence of reasoning
 * attached to each row, and the reasoning is there to be judged against the number beside
 * it rather than instead of it.
 *
 * ## It changes nothing
 *
 * No parameter is written. The command prints the `backtest:optimise` invocation that
 * would confirm a winner independently, and leaves applying it to a person. A proposer
 * that could edit the strategy it is proposing about would be marking its own homework -
 * and BACKTESTING.md exists because these settings used to be opinions.
 */
class ImproveStrategy extends Command
{
    protected $signature = 'strategy:improve
                            {strategy? : Strategy id. Defaults to the only active one}
                            {--account= : Broker account id the candles belong to}
                            {--symbol= : Series to measure. Defaults to the resolved symbol on the heartbeat}
                            {--folds=4 : Walk-forward folds}
                            {--min-trades=10 : Fold results below this are not counted}
                            {--from= : Only use bars from this date}
                            {--to= : Only use bars up to this date}
                            {--spread= : Override the spread assumption, in pips}
                            {--slippage=0.3 : Adverse slippage per market fill, in pips}
                            {--commission=0 : Commission per lot per round trip}
                            {--balance=1000 : Starting balance for the simulation}';

    protected $description = 'Have a model propose strategy parameters, then walk-forward validate them';

    public function handle(StrategyProposer $proposer, WalkForward $walkForward): int
    {
        if (! $proposer->configured()) {
            $this->error('No OPENROUTER_API_KEY is configured, so nothing can be proposed.');
            $this->line('  backtest:optimise still works without it - it just needs you to pick the grid.');

            return self::FAILURE;
        }

        $strategy = $this->resolveStrategy();

        if ($strategy === null) {
            return self::FAILURE;
        }

        $heartbeat = BotHeartbeat::where('user_id', $strategy->user_id)->orderByDesc('last_seen_at')->first();
        $accountId = $this->option('account') !== null ? (int) $this->option('account') : $heartbeat?->broker_account_id;
        // An override matters when the newest heartbeat describes a different series to
        // the one holding the history - a stale fixture, or a broker whose suffix changed.
        $symbol = $this->option('symbol') ?: ($heartbeat?->resolved_symbol ?: $strategy->symbol);

        $entry = $this->candles($accountId, $symbol, $strategy->timeframe_entry);
        $trend = $this->candles($accountId, $symbol, $strategy->timeframe_trend);

        if ($entry === []) {
            $this->error("No {$strategy->timeframe_entry} candles stored for {$symbol}.");

            return self::FAILURE;
        }

        $market = MarketAssumptions::fromHeartbeat($heartbeat, array_filter([
            'spreadPips' => $this->option('spread') !== null ? (float) $this->option('spread') : null,
            'slippagePips' => (float) $this->option('slippage'),
            'commissionPerLot' => (float) $this->option('commission'),
            'startingBalance' => (float) $this->option('balance'),
        ], fn ($v) => $v !== null));

        $settings = BotSettings::where('user_id', $strategy->user_id)->first();
        $folds = (int) $this->option('folds');
        $minTrades = (int) $this->option('min-trades');

        $this->newLine();
        $this->line("<options=bold>{$strategy->name}</> on {$symbol} · ".count($entry).' entry bars');

        // Measure the current settings first. Without a baseline "expectancy +0.42" means
        // nothing - the only question worth answering is whether a change is an improvement.
        $this->line('Measuring the current parameters as a baseline…');

        $baseline = $walkForward->run(
            $strategy, $entry, $trend, [[]], $market, $settings, $folds, $minTrades,
        );

        $baselineOos = $baseline->outOfSample();
        $this->line('  '.$this->describe($baselineOos));
        $this->newLine();

        $evidence = [
            'data_range' => sprintf(
                '%s bars of %s from %s to %s',
                count($entry),
                $strategy->timeframe_entry,
                $entry[0]->open_time->format('Y-m-d'),
                $entry[count($entry) - 1]->open_time->format('Y-m-d'),
            ),
            'baseline' => $this->describe($baselineOos),
            'skip_reasons' => $this->skipReasons($strategy),
        ];

        $this->line('Asking for proposals…');
        $result = $proposer->propose($strategy, $evidence);

        if (! $result['ok']) {
            $this->error($result['error']);

            return self::FAILURE;
        }

        $proposals = $result['proposals'];
        $this->line('  '.count($proposals)." proposal(s) from {$result['model']}");
        $this->newLine();

        $combinations = array_map(fn (array $p) => $p['parameters'], $proposals);

        $bar = $this->output->createProgressBar();
        $bar->start();

        $report = $walkForward->run(
            $strategy, $entry, $trend, $combinations, $market, $settings, $folds, $minTrades,
            fn () => $bar->advance(),
        );

        $bar->finish();
        $this->newLine(2);

        foreach ($report->notes as $note) {
            $this->warn($note);
        }

        $oos = $report->outOfSample();

        $this->line('<options=bold>Out-of-sample, stitched across folds</>');
        $this->table(['Metric', 'Baseline', 'Proposed'], [
            ['Trades', $baselineOos['trades'], $oos['trades']],
            ['Net P&L', $this->signed($baselineOos['net_pnl']), $this->signed($oos['net_pnl'])],
            ['Win rate', $baselineOos['win_rate'].'%', $oos['win_rate'].'%'],
            ['Expectancy', $this->signed($baselineOos['expectancy']), $this->signed($oos['expectancy'])],
            ['Profitable folds', $this->folds($baselineOos), $this->folds($oos)],
        ]);

        $this->newLine();
        $this->line('<options=bold>What was proposed, and why</>');

        foreach ($proposals as $i => $proposal) {
            $changes = [];

            foreach ($proposal['parameters'] as $name => $value) {
                $changes[] = "{$name}=".rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.');
            }

            $this->line(sprintf('  %d. %s', $i + 1, implode('  ', $changes)));
            $this->line('     <fg=gray>'.$proposal['rationale'].'</>');
        }

        $this->newLine();

        // The honest closing note. A walk-forward across a handful of folds on one
        // instrument is evidence, not proof, and the reasoning above is not evidence at all.
        $this->line('<options=bold>Before you apply anything</>');
        $this->line('  Nothing has been changed. Confirm a candidate independently:');
        $this->line('    php artisan backtest:optimise '.$strategy->id.' --param="name=value,value"');
        $this->line('  A proposal that does not beat the baseline out of sample is a proposal that failed,');
        $this->line('  however well it reads.');

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $oos
     */
    private function describe(array $oos): string
    {
        if (($oos['trades'] ?? 0) === 0) {
            return 'no out-of-sample trades - there is not enough history, or the entry rule is too selective';
        }

        return sprintf(
            '%d trades, net %s, %s%% wins, expectancy %s, %d of %d folds profitable',
            $oos['trades'],
            $this->signed($oos['net_pnl']),
            $oos['win_rate'],
            $this->signed($oos['expectancy']),
            $oos['folds_profitable'] ?? 0,
            $oos['folds_tested'] ?? 0,
        );
    }

    /**
     * @param  array<string, mixed>  $oos
     */
    private function folds(array $oos): string
    {
        return ($oos['folds_profitable'] ?? 0).' of '.($oos['folds_tested'] ?? 0);
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

    private function resolveStrategy(): ?Strategy
    {
        if ($this->argument('strategy')) {
            $strategy = Strategy::find((int) $this->argument('strategy'));

            if ($strategy === null) {
                $this->error('No strategy with id '.$this->argument('strategy').'.');
            }

            return $strategy;
        }

        $active = Strategy::where('is_active', true)->get();

        if ($active->count() === 1) {
            return $active->first();
        }

        $this->error($active->isEmpty()
            ? 'No active strategy. Name one explicitly.'
            : 'Several active strategies. Name the one you mean.');

        return null;
    }

    /**
     * @return array<int, Candle>
     */
    private function candles(?int $accountId, string $symbol, string $timeframe): array
    {
        return Candle::query()
            ->when($accountId !== null, fn ($q) => $q->where('broker_account_id', $accountId))
            ->where('symbol', $symbol)
            ->where('timeframe', strtoupper($timeframe))
            ->when($this->option('from'), fn ($q, $from) => $q->where('open_time', '>=', $from))
            ->when($this->option('to'), fn ($q, $to) => $q->where('open_time', '<=', $to))
            ->orderBy('open_time')
            ->get()
            ->all();
    }
}
