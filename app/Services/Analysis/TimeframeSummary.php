<?php

namespace App\Services\Analysis;

use App\Models\Candle;
use App\Models\Strategy;
use App\Services\Indicators\Indicators;
use App\Services\Indicators\Structure;
use App\Services\Strategy\StrategyEvaluator;
use Illuminate\Support\Facades\Cache;

/**
 * Timeframe Summary
 *
 * The same instrument read on several timeframes at once, so that "the trend" stops being
 * a claim about one chart and becomes a comparison between charts.
 *
 * ## Why the timeframes have roles rather than being a list
 *
 * A daily chart and a five-minute chart are not two opinions about the same question. The
 * daily says what regime this is, the entry timeframe says whether a trade can be taken in
 * the next hour, and confusing the two produces the two classic errors in opposite
 * directions: entering against a regime because the fast chart looked good, and refusing a
 * clean entry because the slow chart has not turned yet.
 *
 * So each timeframe is labelled with the job it does. The roles come from the strategy's
 * own configuration where possible - `timeframe_trend` and `timeframe_entry` are already
 * the two this system trades on - and the wider context timeframes are derived from them
 * rather than hardcoded, so an M1 scalper and an H4 swing trader both get a ladder that
 * means something.
 *
 * ## Every number here is measured
 *
 * Nothing in this class asks a model anything. `trend` reuses `StrategyEvaluator`'s own EMA
 * definition - the same one the strategy trades and the same one the dashboard's trend card
 * displays, because three definitions of "bullish" in one product is three chances to
 * contradict yourself in front of a customer. `strength` is ADX, which is a published
 * measurement of trend strength rather than a number invented to look confident.
 *
 * ## On agreement
 *
 * The summary reports whether the timeframes agree; it does not decide what to do about it.
 * Alignment across a ladder is a genuine edge and it is also the condition that occurs
 * least often, so a system that required it would trade rarely and a system that ignored it
 * would trade badly. That trade-off belongs to the strategy and to the person reading, not
 * to the thing doing the measuring.
 */
final class TimeframeSummary
{
    /** Bars per timeframe. Enough for a 200-period EMA to be warm, few enough to stay cheap. */
    private const BARS = 260;

    /** Below this an ADX reading is not describing a trend at all. */
    private const ADX_TRENDING = 20.0;

    /**
     * The ladder, coarsest first, with what each rung is for.
     *
     * MetaTrader's own timeframe names, because those are what the terminal publishes and
     * what `candles.timeframe` stores.
     */
    private const LADDER = ['MN1', 'W1', 'D1', 'H4', 'H1', 'M30', 'M15', 'M5', 'M1'];

    public function __construct(
        private readonly StrategyEvaluator $evaluator = new StrategyEvaluator,
    ) {}

    /**
     * Read one instrument across the ladder around the strategy's own two timeframes.
     *
     * @param  array<int, string>|null  $timeframes  Override the derived ladder
     * @return array{
     *     timeframes: array<string, array<string, mixed>>,
     *     aligned: bool,
     *     agreement: string,
     *     bias: string|null,
     *     read: int
     * }
     */
    public function of(Strategy $strategy, ?int $brokerAccountId, string $symbol, ?array $timeframes = null): array
    {
        $wanted = $timeframes ?? $this->ladderFor($strategy);
        $summaries = [];

        foreach ($wanted as $timeframe) {
            $reading = $this->read($strategy, $brokerAccountId, $symbol, $timeframe);

            // A timeframe with no bars is omitted rather than reported as neutral. "We
            // have not got that chart" and "that chart is undecided" are different facts,
            // and a null trend rendered as a grey pill reads as the second.
            if ($reading !== null) {
                $summaries[$timeframe] = $reading;
            }
        }

        return $this->conclude($summaries);
    }

    /**
     * Which timeframes to read, given what the strategy actually trades.
     *
     * The strategy's trend and entry timeframes are always included - they are the two the
     * decision is made on. Around them: one rung wider for regime, and one rung finer for
     * entry timing. Derived rather than fixed so this is useful to an M1 scalper and an H4
     * swing trader without either being handed somebody else's ladder.
     *
     * @return array<int, string>
     */
    public function ladderFor(Strategy $strategy): array
    {
        $trend = strtoupper((string) $strategy->timeframe_trend);
        $entry = strtoupper((string) $strategy->timeframe_entry);

        $trendAt = array_search($trend, self::LADDER, true);
        $entryAt = array_search($entry, self::LADDER, true);

        if ($trendAt === false || $entryAt === false) {
            return array_values(array_unique(array_filter([$trend, $entry])));
        }

        // Coarsest index is the smallest, so "wider" means one step back down the array.
        $widest = max(0, min($trendAt, $entryAt) - 1);
        $finest = min(count(self::LADDER) - 1, max($trendAt, $entryAt) + 1);

        return array_slice(self::LADDER, $widest, $finest - $widest + 1);
    }

    /**
     * One timeframe, or null if there are not enough bars to say anything.
     *
     * ## Cached per timeframe rather than per ladder
     *
     * Reading five rungs means five series of 260 bars, and on a page that also asks a
     * model something that arithmetic was being redone on every render for no benefit -
     * the answer cannot change until a bar closes.
     *
     * Keyed per rung rather than for the ladder as a whole, because the rungs move at
     * wildly different speeds. A daily reading is good for a day; keying the whole ladder
     * on the fastest rung would throw the daily away every five minutes and recompute an
     * answer that had not changed.
     *
     * The key is the newest bar's timestamp, so it expires by the only clock that matters.
     * Establishing it costs one indexed `max()` rather than hydrating the series, which is
     * the whole saving.
     *
     * @return array<string, mixed>|null
     */
    private function read(Strategy $strategy, ?int $brokerAccountId, string $symbol, string $timeframe): ?array
    {
        $newest = Candle::query()
            ->series($brokerAccountId, $symbol, $timeframe)
            ->max('open_time');

        if ($newest === null) {
            return null;
        }

        $key = sprintf('tf-summary:%s:%s:%s:%s:%d', $brokerAccountId ?? 'any', $symbol, $timeframe, $newest, $strategy->id);

        return Cache::remember(
            $key,
            now()->addMinutes(max(1, (int) config('ai.cache_minutes'))),
            fn () => $this->measure($strategy, $brokerAccountId, $symbol, $timeframe),
        );
    }

    /**
     * The arithmetic itself, with nothing cached.
     *
     * @return array<string, mixed>|null
     */
    private function measure(Strategy $strategy, ?int $brokerAccountId, string $symbol, string $timeframe): ?array
    {
        $candles = Candle::recentSeries($brokerAccountId, $symbol, $timeframe, self::BARS);

        // The slow EMA plus a couple of bars, which is the same floor `trendDirection()`
        // applies internally. Reporting a trend from a series shorter than its own
        // indicator is how a freshly connected terminal produces confident nonsense.
        if (count($candles) < (int) $strategy->ema_slow + 2) {
            return null;
        }

        $closes = Candle::closes($candles);
        $highs = Candle::highs($candles);
        $lows = Candle::lows($candles);

        $direction = $this->evaluator->trendDirection($candles, (int) $strategy->ema_fast, (int) $strategy->ema_slow);
        $adx = Indicators::last(Indicators::adx($highs, $lows, $closes, 14)['adx']);
        $atr = Indicators::last(Indicators::atr($highs, $lows, $closes, 14)) ?? 0.0;

        $structure = Structure::sequence($highs, $lows, $closes);
        $rsi = Indicators::last(Indicators::rsi($closes, 14));

        return [
            'role' => $this->roleOf($timeframe, $strategy),
            // 'bullish' / 'bearish' rather than the strategy layer's 'buy' / 'sell': this
            // is a description of a chart, not an instruction to place an order, and the
            // vocabulary should not blur the two.
            'trend' => match ($direction) {
                'buy' => 'bullish',
                'sell' => 'bearish',
                default => 'undecided',
            },
            // ADX, rounded. Not a confidence score and not a probability - it says how
            // directional recent movement has been, and nothing about what happens next.
            'strength' => $adx === null ? null : (int) round($adx),
            'trending' => $adx !== null && $adx >= self::ADX_TRENDING,
            'structure' => $structure['bias'],
            'last_event' => $structure['last_event']['type'] ?? null,
            'last_event_direction' => $structure['last_event']['direction'] ?? null,
            'rsi' => $rsi === null ? null : round($rsi, 1),
            'atr' => round($atr, 6),
            'bars' => count($candles),
        ];
    }

    /**
     * What this rung is for, in the ladder this strategy trades.
     */
    private function roleOf(string $timeframe, Strategy $strategy): string
    {
        return match (strtoupper($timeframe)) {
            strtoupper((string) $strategy->timeframe_trend) => 'primary trend',
            strtoupper((string) $strategy->timeframe_entry) => 'setup structure',
            default => $this->isWiderThan($timeframe, (string) $strategy->timeframe_trend)
                ? 'market context'
                : 'entry timing',
        };
    }

    private function isWiderThan(string $timeframe, string $than): bool
    {
        $a = array_search(strtoupper($timeframe), self::LADDER, true);
        $b = array_search(strtoupper($than), self::LADDER, true);

        return $a !== false && $b !== false && $a < $b;
    }

    /**
     * Do the timeframes agree, and on what.
     *
     * @param  array<string, array<string, mixed>>  $summaries
     * @return array{
     *     timeframes: array<string, array<string, mixed>>,
     *     aligned: bool,
     *     agreement: string,
     *     bias: string|null,
     *     read: int
     * }
     */
    private function conclude(array $summaries): array
    {
        $directional = array_values(array_filter(
            array_column($summaries, 'trend'),
            fn (string $t) => $t !== 'undecided'
        ));

        if ($directional === []) {
            return [
                'timeframes' => $summaries,
                'aligned' => false,
                'agreement' => 'No timeframe is pointing anywhere in particular.',
                'bias' => null,
                'read' => count($summaries),
            ];
        }

        $bull = count(array_filter($directional, fn (string $t) => $t === 'bullish'));
        $bear = count($directional) - $bull;
        $aligned = $bull === 0 || $bear === 0;

        $bias = match (true) {
            $bull > $bear => 'bullish',
            $bear > $bull => 'bearish',
            // A genuine tie is not a bias. Picking one because something has to be picked
            // is how a coin flip acquires an explanation.
            default => null,
        };

        return [
            'timeframes' => $summaries,
            'aligned' => $aligned,
            'agreement' => $aligned
                ? sprintf('All %d timeframes read %s.', count($directional), $directional[0])
                : sprintf('%d bullish against %d bearish: the timeframes disagree.', $bull, $bear),
            'bias' => $bias,
            'read' => count($summaries),
        ];
    }
}
