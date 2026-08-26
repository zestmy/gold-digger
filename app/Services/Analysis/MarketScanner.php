<?php

namespace App\Services\Analysis;

use App\Models\Candle;
use App\Models\Strategy;
use App\Services\Indicators\Structure;
use App\Services\Instruments\InstrumentProfile;
use App\Services\Strategy\MarketContext;
use App\Services\Strategy\SignalQuality;
use App\Services\Strategy\StrategyEvaluator;

/**
 * Market Scanner
 *
 * Reads every instrument there are stored bars for, and ranks them.
 *
 * ## Why the ranking is mechanical
 *
 * "Find me the profitable pair" is a question nobody can answer, and the honest version of
 * it is answerable: of the instruments this account has history for, which ones currently
 * have the most independent things agreeing about a direction, and a level close enough
 * behind price to put a stop beyond and one far enough ahead to be worth aiming at.
 *
 * That is arithmetic, so it happens here. No model is consulted to produce this list, the
 * whole scan costs nothing to run, and the ordering can be recomputed from stored data by
 * anybody who disagrees with it.
 *
 * ## Why it reuses SignalQuality rather than scoring afresh
 *
 * `SignalQuality` is what the copier scores entries with. A second scoring function would
 * drift from it, and the first sign of the drift would be this page recommending an
 * instrument the executor then refuses to trade for reasons this page never mentioned.
 * The scan is therefore the same measurement applied more widely, not a new one.
 *
 * ## What "tradeable" means here, and what it does not
 *
 * It means the entry would clear the confluence floor if it were offered right now. It is
 * not a prediction, this class opens nothing, and the ordering says which setups are best
 * evidenced rather than which will make money. The walk-forward numbers remain the only
 * thing this project has that speaks to the second question.
 */
final class MarketScanner
{
    /** Bars needed on the scan timeframe before structure is worth reading. */
    public const MIN_BARS = 60;

    /** Bars of structure to read. Matches ChartAnalyst, so the two agree about levels. */
    private const BARS = 120;

    /**
     * How far beyond the level the stop sits, in ATR.
     *
     * A stop exactly on a level gets taken out by the wick that tests it. The buffer is a
     * fraction of the instrument's own volatility rather than a fixed number of points,
     * because a distance that is generous on gold is invisible on an index.
     */
    private const STOP_BUFFER_ATR = 0.25;

    /**
     * A target closer than this to price is not a target.
     *
     * The nearest level ahead is sometimes a few ticks away, which produces a spectacular
     * reward ratio against a stop that has to sit behind a level much further back. Such a
     * plan is arithmetically fine and practically meaningless, so the next level out is
     * used instead.
     */
    private const MIN_TARGET_ATR = 0.5;

    public function __construct(
        private readonly SignalQuality $quality = new SignalQuality,
        private readonly MarketContext $context = new MarketContext(new StrategyEvaluator),
        private readonly InstrumentProfile $profile = new InstrumentProfile,
    ) {}

    /**
     * Every instrument this account has enough bars of, on this timeframe.
     *
     * Scoped to the broker account because candles are stored per account: asking without
     * it returns another terminal's series, which looks like history and is not this
     * account's.
     *
     * @return array<int, string>
     */
    public static function symbols(?int $brokerAccountId, string $timeframe): array
    {
        return Candle::query()
            ->select('symbol')
            ->where('broker_account_id', $brokerAccountId)
            ->where('timeframe', $timeframe)
            ->groupBy('symbol')
            ->havingRaw('count(*) >= ?', [self::MIN_BARS])
            ->orderBy('symbol')
            ->pluck('symbol')
            ->all();
    }

    /**
     * Scan, and return the instruments in the order they deserve attention.
     *
     * @param  array<int, string>|null  $symbols  Defaults to everything with enough history
     * @return array{
     *     timeframe: string,
     *     scanned: int,
     *     candidates: array<int, Opportunity>,
     *     skipped: array<int, array{symbol: string, why: string}>
     * }
     */
    public function scan(Strategy $strategy, ?int $brokerAccountId, string $timeframe, ?array $symbols = null): array
    {
        $symbols ??= self::symbols($brokerAccountId, $timeframe);

        $candidates = [];
        $skipped = [];

        foreach ($symbols as $symbol) {
            $found = $this->consider($strategy, $brokerAccountId, $symbol, $timeframe);

            if ($found instanceof Opportunity) {
                $candidates[] = $found;

                continue;
            }

            // Named rather than dropped. An instrument missing from the results because it
            // has no bias reads identically to one missing because nobody ever stored bars
            // for it, and only one of those is worth doing something about.
            $skipped[] = ['symbol' => $symbol, 'why' => $found];
        }

        usort($candidates, fn (Opportunity $a, Opportunity $b) => $b->rank() <=> $a->rank());

        return [
            'timeframe' => $timeframe,
            'scanned' => count($symbols),
            'candidates' => $candidates,
            'skipped' => $skipped,
        ];
    }

    /**
     * One instrument: an opportunity, or the reason there is not one.
     *
     * @return Opportunity|string
     */
    public function consider(Strategy $strategy, ?int $brokerAccountId, string $symbol, string $timeframe)
    {
        $market = $this->context->for($strategy, $brokerAccountId, $symbol);

        if (! $market['warm']) {
            return sprintf(
                'Not enough history for the indicators: %d bars on %s, %d on %s.',
                $market['bars_entry'], $market['entry_timeframe'],
                $market['bars_trend'], $market['trend_timeframe'],
            );
        }

        $atr = $market['atr'];

        if ($atr === null || $atr <= 0.0) {
            // Without ATR there is no stop distance, and without a stop distance there is
            // nothing here that could be sized.
            return 'No ATR, so no stop distance exists.';
        }

        $direction = $market['entry_bias'];

        if ($direction === null) {
            // The EMAs are on top of each other. There is no direction to score against,
            // and scoring both would be scoring neither.
            return 'The entry EMAs are level: no direction to test.';
        }

        $bars = Candle::query()
            ->series($brokerAccountId, $symbol, $timeframe)
            ->orderByDesc('open_time')
            ->limit(self::BARS)
            ->get()
            ->reverse()
            ->values();

        if ($bars->count() < self::MIN_BARS) {
            return "Only {$bars->count()} {$timeframe} bars stored.";
        }

        $structure = Structure::of(
            $bars->map(fn (Candle $c) => (float) $c->high)->all(),
            $bars->map(fn (Candle $c) => (float) $c->low)->all(),
            $bars->map(fn (Candle $c) => (float) $c->close)->all(),
            $atr,
        );

        // The snapshot is handed over rather than recomputed: two reads of the same series
        // per symbol is a real cost across a whole watchlist, for an answer that cannot
        // have changed between them.
        $assessment = $this->quality->assess($strategy, $brokerAccountId, $symbol, $direction, market: $market);

        $entry = (float) $bars->last()->close;
        $plan = $this->plan($direction, $entry, $atr, $structure['levels']);

        return new Opportunity(
            symbol: $symbol,
            kind: $this->profile->for($symbol)['kind'],
            direction: $direction,
            confluence: (float) $assessment['confluence'],
            possible: (float) array_sum(array_column($assessment['factors'], 'weight')),
            directional: (float) $assessment['directional'],
            confidence: (int) $assessment['confidence'],
            risk: (string) $assessment['risk'],
            entryStatus: (string) $assessment['entry_status'],
            tradeable: (bool) $assessment['tradeable'],
            why: (string) $assessment['why'],
            factors: $assessment['factors'],
            aligned: (bool) $market['aligned'],
            adx: $market['adx'],
            atr: $atr,
            atrPct: $market['atr_pct'],
            entry: $entry,
            stop: $plan['stop'],
            target: $plan['target'],
            rewardRatio: $plan['reward_ratio'],
            stopLevel: $plan['stop_level'],
            targetLevel: $plan['target_level'],
            structure: (string) $structure['structure'],
            levels: $structure['levels'],
            lastBarAt: $bars->last()->open_time,
            bars: $bars->count(),
        );
    }

    /**
     * Where the stop and the target go, given the measured levels.
     *
     * Both come from the list or they do not come at all. The stop sits beyond the nearest
     * level behind price, because that is the price at which the reason for the trade
     * stopped being true; the target is the nearest level ahead that is far enough away to
     * be worth the trip. Neither is rounded, nudged, or chosen to make the ratio look
     * better.
     *
     * @param  'buy'|'sell'  $direction
     * @param  array<int, array{price: float, kind: string, touches: int, last_index: int}>  $levels
     * @return array{
     *     stop: float|null, target: float|null, reward_ratio: float|null,
     *     stop_level: array<string, mixed>|null, target_level: array<string, mixed>|null
     * }
     */
    private function plan(string $direction, float $entry, float $atr, array $levels): array
    {
        $below = array_values(array_filter($levels, fn (array $l) => (float) $l['price'] < $entry));
        $above = array_values(array_filter($levels, fn (array $l) => (float) $l['price'] > $entry));

        usort($below, fn (array $a, array $b) => $b['price'] <=> $a['price']); // nearest below first
        usort($above, fn (array $a, array $b) => $a['price'] <=> $b['price']); // nearest above first

        $behind = $direction === 'buy' ? $below : $above;
        $ahead = $direction === 'buy' ? $above : $below;

        $stopLevel = $behind[0] ?? null;

        // The first level ahead that is actually somewhere. Skipping the ones inside half
        // an ATR keeps the ratio honest rather than flattering.
        $targetLevel = null;

        foreach ($ahead as $level) {
            if (abs((float) $level['price'] - $entry) >= $atr * self::MIN_TARGET_ATR) {
                $targetLevel = $level;
                break;
            }
        }

        $buffer = $atr * self::STOP_BUFFER_ATR;

        $stop = $stopLevel === null
            ? null
            : ($direction === 'buy'
                ? (float) $stopLevel['price'] - $buffer
                : (float) $stopLevel['price'] + $buffer);

        $target = $targetLevel === null ? null : (float) $targetLevel['price'];

        $risk = $stop === null ? null : abs($entry - $stop);
        $reward = $target === null ? null : abs($target - $entry);

        return [
            'stop' => $stop,
            'target' => $target,
            'stop_level' => $stopLevel,
            'target_level' => $targetLevel,
            // Arithmetic, computed once, and never asked of a model.
            'reward_ratio' => ($risk !== null && $reward !== null && $risk > 0.0)
                ? round($reward / $risk, 2)
                : null,
        ];
    }
}
