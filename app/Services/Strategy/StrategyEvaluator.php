<?php

namespace App\Services\Strategy;

use App\Models\Candle;
use App\Models\Strategy;
use App\Services\Indicators\Indicators;

/**
 * Strategy Evaluator
 *
 * Turns two candle series into a Setup, or into nothing at all. This is the whole of the
 * entry rule the `strategies` table describes:
 *
 *   1. The trend timeframe (H1 by default) sets the only direction that may be traded:
 *      fast EMA above slow EMA means longs only, below means shorts only.
 *   2. On the entry timeframe (M5), the fast EMA must *cross* the slow EMA on the most
 *      recent closed bar, in that same direction.
 *   3. ADX and ATR readings are attached to the setup but not judged here - the
 *      thresholds are filters, and SignalGenerator records what they rejected.
 *
 * ## Why a cross, not a state
 *
 * "Fast is above slow" is true for hundreds of consecutive bars. Entering on the state
 * would re-enter on every bar of a trend. Entering on the transition means one signal per
 * crossing, which is what a crossover strategy means by a signal.
 *
 * ## Why only the most recent closed bar
 *
 * The evaluator answers "is there a signal right now", not "where were all the signals".
 * A cross three bars ago is not tradeable now at anything like the price that justified
 * it. Backtesting over history is a different job and would walk the series deliberately.
 *
 * ## ADX period
 *
 * `strategies` stores an ADX *threshold* but no ADX period, so `atr_period` is used for
 * both. That is the conventional 14 for each, and inventing a second column to hold the
 * same number would be schema for its own sake.
 */
final class StrategyEvaluator
{
    /**
     * Bars of history to load per series.
     *
     * ADX needs 2 * period bars before it reads at all, and the EMA seed needs ema_slow.
     * This is a generous ceiling over both, so a long EMA on a short ADX still warms up.
     */
    public const LOOKBACK_BARS = 300;

    /**
     * @param  array<int, Candle>  $entryCandles  Oldest-first, entry timeframe
     * @param  array<int, Candle>  $trendCandles  Oldest-first, trend timeframe
     */
    public function evaluate(Strategy $strategy, array $entryCandles, array $trendCandles): ?Setup
    {
        $emaFast = (int) $strategy->ema_fast;
        $emaSlow = (int) $strategy->ema_slow;
        $period = (int) $strategy->atr_period;

        // A cross needs two readings of both EMAs, so the slow one must have warmed up
        // by the second-to-last bar. ADX warm-up is the longer constraint of the two.
        $minEntryBars = max($emaSlow + 2, (2 * $period) + 1);

        if (count($entryCandles) < $minEntryBars || count($trendCandles) < $emaSlow + 1) {
            return null;
        }

        $trendDirection = $this->trendDirection($trendCandles, $emaFast, $emaSlow);

        if ($trendDirection === null) {
            return null;
        }

        $closes = Candle::closes($entryCandles);
        $fast = Indicators::ema($closes, $emaFast);
        $slow = Indicators::ema($closes, $emaSlow);

        $last = count($closes) - 1;
        $prev = $last - 1;

        $direction = $this->crossDirection($entryCandles, $emaFast, $emaSlow);

        if ($direction === null) {
            return null;
        }

        // The higher timeframe has a veto. Counter-trend crosses are the ones that whipsaw.
        if ($direction !== $trendDirection) {
            return null;
        }

        $highs = array_map(static fn (Candle $c) => (float) $c->high, $entryCandles);
        $lows = array_map(static fn (Candle $c) => (float) $c->low, $entryCandles);

        $atr = Indicators::last(Indicators::atr($highs, $lows, $closes, $period));
        $adxSeries = Indicators::adx($highs, $lows, $closes, $period);
        $adx = Indicators::last($adxSeries['adx']);

        // ATR sets the stop distance. Without it there is no stop, and a position with
        // no stop is not a trade this system is willing to describe.
        if ($atr === null || $atr <= 0.0 || $adx === null) {
            return null;
        }

        /** @var Candle $signalBar */
        $signalBar = $entryCandles[$last];

        return new Setup(
            direction: $direction,
            entryPrice: (float) $signalBar->close,
            atr: $atr,
            adx: $adx,
            barTime: $signalBar->open_time,
            features: [
                'ema_fast' => round($fast[$last], 5),
                'ema_slow' => round($slow[$last], 5),
                'ema_fast_prev' => round($fast[$prev], 5),
                'ema_slow_prev' => round($slow[$prev], 5),
                'trend_direction' => $trendDirection,
                'adx' => round($adx, 4),
                'plus_di' => $this->rounded(Indicators::last($adxSeries['plus_di'])),
                'minus_di' => $this->rounded(Indicators::last($adxSeries['minus_di'])),
                'atr' => round($atr, 5),
                'spread_points' => $signalBar->spread_points,
                'entry_timeframe' => $strategy->timeframe_entry,
                'trend_timeframe' => $strategy->timeframe_trend,
                'bar_close' => (float) $signalBar->close,
            ],
        );
    }

    /**
     * Direction of an EMA crossover on the most recent closed bar, or null if there was none.
     *
     * Public because the exit side needs the same question answered: `exit_on_reversal`
     * closes a position when the EMAs cross *back*, and that has to be the same crossover
     * this class uses to enter. Two implementations would drift, and a strategy that
     * entered on one definition and exited on another would behave in ways neither
     * definition explains.
     *
     * @param  array<int, Candle>  $candles  Oldest-first
     * @return 'buy'|'sell'|null
     */
    public function crossDirection(array $candles, int $emaFast, int $emaSlow): ?string
    {
        if (count($candles) < $emaSlow + 2) {
            return null;
        }

        $closes = Candle::closes($candles);
        $fast = Indicators::ema($closes, $emaFast);
        $slow = Indicators::ema($closes, $emaSlow);

        $last = count($closes) - 1;
        $prev = $last - 1;

        // Any null here means the series has a gap the warm-up check did not catch.
        if ($fast[$last] === null || $slow[$last] === null || $fast[$prev] === null || $slow[$prev] === null) {
            return null;
        }

        if ($fast[$prev] <= $slow[$prev] && $fast[$last] > $slow[$last]) {
            return 'buy';
        }

        if ($fast[$prev] >= $slow[$prev] && $fast[$last] < $slow[$last]) {
            return 'sell';
        }

        return null;
    }

    /**
     * Which way the higher timeframe is pointing, or null while its EMAs are still cold.
     *
     * Public for the same reason `crossDirection()` is: the dashboard's trend card shows
     * this to a human, and a card that called the trend bullish while the strategy that
     * trades it called it bearish would be worse than showing nothing. One definition,
     * read by both.
     *
     * @param  array<int, Candle>  $trendCandles
     * @return 'buy'|'sell'|null
     */
    public function trendDirection(array $trendCandles, int $emaFast, int $emaSlow): ?string
    {
        $closes = Candle::closes($trendCandles);

        $fast = Indicators::last(Indicators::ema($closes, $emaFast));
        $slow = Indicators::last(Indicators::ema($closes, $emaSlow));

        if ($fast === null || $slow === null) {
            return null;
        }

        // Exactly equal is not a trend. Rare on real prices, but a flat synthetic series
        // would otherwise report a direction it does not have.
        if ($fast === $slow) {
            return null;
        }

        return $fast > $slow ? 'buy' : 'sell';
    }

    private function rounded(?float $value): ?float
    {
        return $value === null ? null : round($value, 4);
    }
}
