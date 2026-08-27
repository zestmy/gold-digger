<?php

namespace App\Services\Indicators;

use InvalidArgumentException;

/**
 * Indicators
 *
 * EMA, ATR and ADX over a closed-bar series. Deliberately pure: plain float arrays in,
 * plain float arrays out, no models and no database. The strategy layer is the part of
 * this system most likely to be wrong in a way that costs money, and keeping the maths
 * free of Eloquent is what lets it be tested against hand-checked fixtures.
 *
 * ## Alignment
 *
 * Every method returns an array the same length as its input, with `null` for bars that
 * fall inside the indicator's warm-up. Index i of the result always describes bar i of
 * the input. Returning a shorter array instead would make callers do the offset
 * arithmetic themselves, and an off-by-one there reads a value from the wrong bar -
 * which is a silent wrong answer, not a crash.
 *
 * ## Why Wilder smoothing
 *
 * ATR and ADX use Wilder's smoothing (alpha = 1/period), not the exponential alpha of
 * 2/(period+1). That is what MetaTrader's iATR and iADX use, and the strategy is
 * configured by people reading those indicators on a chart. Matching the platform
 * matters more here than matching any textbook: a stop placed at 1.5 ATR should sit
 * where the trader's own chart says 1.5 ATR is.
 *
 * The series must be oldest-first. Candle::recentSeries() guarantees that.
 */
final class Indicators
{
    /**
     * Exponential moving average.
     *
     * Seeded with a simple average of the first `period` values, which is how MetaTrader
     * seeds iMA. An EMA seeded from a single first value converges to the same numbers
     * eventually but disagrees for the first few hundred bars - long enough to matter on
     * a freshly populated series.
     *
     * @param  array<int, float>  $values
     * @return array<int, float|null>
     */
    public static function ema(array $values, int $period): array
    {
        self::guardPeriod($period);

        $count = count($values);
        $out = array_fill(0, $count, null);

        if ($count < $period) {
            return $out;
        }

        $seed = array_sum(array_slice($values, 0, $period)) / $period;
        $out[$period - 1] = $seed;

        $alpha = 2.0 / ($period + 1);

        for ($i = $period; $i < $count; $i++) {
            $out[$i] = ($values[$i] * $alpha) + ($out[$i - 1] * (1.0 - $alpha));
        }

        return $out;
    }

    /**
     * True range per bar.
     *
     * Index 0 is null: true range is defined against the previous close, and the first
     * bar of a series has none. Using its own high-low there would understate the first
     * reading and drag the ATR seed down with it.
     *
     * @param  array<int, float>  $highs
     * @param  array<int, float>  $lows
     * @param  array<int, float>  $closes
     * @return array<int, float|null>
     */
    public static function trueRange(array $highs, array $lows, array $closes): array
    {
        self::guardSameLength($highs, $lows, $closes);

        $count = count($highs);
        $out = array_fill(0, $count, null);

        for ($i = 1; $i < $count; $i++) {
            $prevClose = $closes[$i - 1];

            $out[$i] = max(
                $highs[$i] - $lows[$i],
                abs($highs[$i] - $prevClose),
                abs($lows[$i] - $prevClose),
            );
        }

        return $out;
    }

    /**
     * Average true range, Wilder-smoothed.
     *
     * First value lands at index `period` - one later than a naive implementation, because
     * true range itself only starts at index 1.
     *
     * @param  array<int, float>  $highs
     * @param  array<int, float>  $lows
     * @param  array<int, float>  $closes
     * @return array<int, float|null>
     */
    public static function atr(array $highs, array $lows, array $closes, int $period): array
    {
        self::guardPeriod($period);
        self::guardSameLength($highs, $lows, $closes);

        $tr = self::trueRange($highs, $lows, $closes);
        $count = count($tr);
        $out = array_fill(0, $count, null);

        // Needs `period` true ranges, and the first usable one is at index 1.
        if ($count < $period + 1) {
            return $out;
        }

        $out[$period] = array_sum(array_slice($tr, 1, $period)) / $period;

        for ($i = $period + 1; $i < $count; $i++) {
            $out[$i] = (($out[$i - 1] * ($period - 1)) + $tr[$i]) / $period;
        }

        return $out;
    }

    /**
     * Average directional index, with the two directional indicators that build it.
     *
     * ADX measures trend *strength* and says nothing about direction - a strong downtrend
     * and a strong uptrend both read high. Direction comes from the EMA relationship; ADX
     * is only ever used here as a gate.
     *
     * Warm-up is long: +DM/-DM start at index 1, Wilder smoothing consumes `period` bars,
     * and ADX is itself a smoothing of DX over another `period`. The first ADX value lands
     * at index 2 * period - 1, so a 14-period ADX needs 28 bars before it reads at all.
     *
     * @param  array<int, float>  $highs
     * @param  array<int, float>  $lows
     * @param  array<int, float>  $closes
     * @return array{adx: array<int, float|null>, plus_di: array<int, float|null>, minus_di: array<int, float|null>}
     */
    public static function adx(array $highs, array $lows, array $closes, int $period): array
    {
        self::guardPeriod($period);
        self::guardSameLength($highs, $lows, $closes);

        $count = count($highs);

        $adx = array_fill(0, $count, null);
        $plusDi = array_fill(0, $count, null);
        $minusDi = array_fill(0, $count, null);

        if ($count < (2 * $period)) {
            return ['adx' => $adx, 'plus_di' => $plusDi, 'minus_di' => $minusDi];
        }

        $tr = self::trueRange($highs, $lows, $closes);

        // Directional movement: only the larger of the two moves counts, and only when
        // it is positive. A bar that is inside the previous one contributes neither.
        $plusDm = array_fill(0, $count, 0.0);
        $minusDm = array_fill(0, $count, 0.0);

        for ($i = 1; $i < $count; $i++) {
            $upMove = $highs[$i] - $highs[$i - 1];
            $downMove = $lows[$i - 1] - $lows[$i];

            $plusDm[$i] = ($upMove > $downMove && $upMove > 0) ? $upMove : 0.0;
            $minusDm[$i] = ($downMove > $upMove && $downMove > 0) ? $downMove : 0.0;
        }

        // Wilder's running sums, seeded with a plain sum over the first `period` bars.
        $smoothTr = array_sum(array_slice($tr, 1, $period));
        $smoothPlus = array_sum(array_slice($plusDm, 1, $period));
        $smoothMinus = array_sum(array_slice($minusDm, 1, $period));

        $dx = array_fill(0, $count, null);

        for ($i = $period; $i < $count; $i++) {
            if ($i > $period) {
                $smoothTr = $smoothTr - ($smoothTr / $period) + $tr[$i];
                $smoothPlus = $smoothPlus - ($smoothPlus / $period) + $plusDm[$i];
                $smoothMinus = $smoothMinus - ($smoothMinus / $period) + $minusDm[$i];
            }

            // A dead-flat window has no range to divide by. Reporting 0 directional
            // strength is right: there is no trend to measure, which is exactly what the
            // ADX gate should reject.
            if ($smoothTr <= 0.0) {
                $plusDi[$i] = 0.0;
                $minusDi[$i] = 0.0;
                $dx[$i] = 0.0;

                continue;
            }

            $plusDi[$i] = 100.0 * ($smoothPlus / $smoothTr);
            $minusDi[$i] = 100.0 * ($smoothMinus / $smoothTr);

            $diSum = $plusDi[$i] + $minusDi[$i];

            $dx[$i] = $diSum <= 0.0
                ? 0.0
                : 100.0 * (abs($plusDi[$i] - $minusDi[$i]) / $diSum);
        }

        // ADX seeds on the average of the first `period` DX readings, then Wilder-smooths.
        $firstAdxIndex = (2 * $period) - 1;

        if ($firstAdxIndex >= $count) {
            return ['adx' => $adx, 'plus_di' => $plusDi, 'minus_di' => $minusDi];
        }

        $adx[$firstAdxIndex] = array_sum(array_slice($dx, $period, $period)) / $period;

        for ($i = $firstAdxIndex + 1; $i < $count; $i++) {
            $adx[$i] = (($adx[$i - 1] * ($period - 1)) + $dx[$i]) / $period;
        }

        return ['adx' => $adx, 'plus_di' => $plusDi, 'minus_di' => $minusDi];
    }

    /**
     * Last non-null value of an indicator series, or null if it never warmed up.
     *
     * @param  array<int, float|null>  $series
     */
    /**
     * Bollinger band width as a fraction of the middle band.
     *
     * The raw width is in price units, which makes it incomparable across instruments and
     * across time on the same one - a 5-point band on gold at 1800 is not the 5-point band
     * it was at 4600. Dividing by the mean makes it a ratio that can be compared with its
     * own history, which is the only comparison that means anything here.
     *
     * @param  array<int, float>  $closes
     * @return array<int, float|null> Null until the window is full
     */
    public static function bandwidth(array $closes, int $period = 20, float $deviations = 2.0): array
    {
        $out = [];
        $n = count($closes);

        for ($i = 0; $i < $n; $i++) {
            if ($i < $period - 1) {
                $out[] = null;

                continue;
            }

            $window = array_slice($closes, $i - $period + 1, $period);
            $mean = array_sum($window) / $period;

            if ($mean == 0.0) {
                $out[] = null;

                continue;
            }

            $variance = 0.0;

            foreach ($window as $value) {
                $variance += ($value - $mean) ** 2;
            }

            // Population rather than sample: this is the whole window, not a draw from it,
            // and the convention Bollinger bands are drawn with.
            $sd = sqrt($variance / $period);

            $out[] = (2 * $deviations * $sd) / abs($mean);
        }

        return $out;
    }

    /**
     * Is volatility compressed relative to its own recent range?
     *
     * The "pre-mover" idea: bands narrowing means the market has stopped disagreeing about
     * price, and disagreement usually returns. What it emphatically does not say is which
     * way - a squeeze precedes a move, not a direction, and treating it as a direction is
     * the mistake this measurement invites.
     *
     * So it is offered as a factor that can support an entry whose direction came from
     * somewhere else, never as a reason to take one.
     *
     * @param  array<int, float>  $closes
     * @param  float  $percentile  How tight counts as tight, against the lookback
     * @return array{squeezed: bool, bandwidth: float|null, threshold: float|null}
     */
    public static function squeeze(array $closes, int $period = 20, int $lookback = 120, float $percentile = 0.25): array
    {
        $widths = self::bandwidth($closes, $period);

        $recent = array_values(array_filter(
            array_slice($widths, -$lookback),
            fn (?float $w) => $w !== null,
        ));

        $current = self::last($widths);

        // Not enough history to say what "narrow" means for this instrument. Reported as
        // unknown rather than as not-squeezed: the two are different, and only one of them
        // should count against a signal.
        if ($current === null || count($recent) < 20) {
            return ['squeezed' => false, 'bandwidth' => $current, 'threshold' => null];
        }

        sort($recent);

        $index = (int) floor($percentile * (count($recent) - 1));
        $threshold = $recent[$index];

        return [
            'squeezed' => $current <= $threshold,
            'bandwidth' => $current,
            'threshold' => $threshold,
        ];
    }

    /**
     * Simple moving average.
     *
     * Here because SMA 50 and SMA 200 are the two lines nearly every chart commentary
     * refers to, so an analysis that cannot say which side of them price is on is talking
     * about a different chart from the one the reader is looking at.
     *
     * @param  array<int, float>  $values
     * @return array<int, float|null>
     */
    public static function sma(array $values, int $period): array
    {
        self::guardPeriod($period);

        $count = count($values);
        $out = array_fill(0, $count, null);
        $running = 0.0;

        for ($i = 0; $i < $count; $i++) {
            $running += $values[$i];

            if ($i >= $period) {
                $running -= $values[$i - $period];
            }

            if ($i >= $period - 1) {
                $out[$i] = $running / $period;
            }
        }

        return $out;
    }

    /**
     * Relative Strength Index.
     *
     * Wilder smoothing, for the same reason ATR and ADX use it: this must agree with the
     * number on the trader's own MetaTrader chart. An RSI computed with a 2/(n+1) alpha
     * reads several points away from iRSI in a trend, which is exactly the situation where
     * somebody is deciding whether 68 counts as overbought.
     *
     * ## On "overbought"
     *
     * It is a momentum reading, not a sell signal. RSI sits above 70 for the whole of a
     * strong trend, and every system that treats that as a reason to fade has discovered
     * the same thing expensively. It is offered here as one factor among several.
     *
     * @param  array<int, float>  $closes
     * @return array<int, float|null> 0..100, null through the warm-up
     */
    public static function rsi(array $closes, int $period = 14): array
    {
        self::guardPeriod($period);

        $count = count($closes);
        $out = array_fill(0, $count, null);

        if ($count <= $period) {
            return $out;
        }

        $gain = 0.0;
        $loss = 0.0;

        // Seed on a simple average of the first `period` changes, which is where Wilder's
        // recursion starts. Note the first close has no change, so the seed lands on index
        // `period` rather than `period - 1`.
        for ($i = 1; $i <= $period; $i++) {
            $change = $closes[$i] - $closes[$i - 1];
            $change >= 0 ? $gain += $change : $loss -= $change;
        }

        $avgGain = $gain / $period;
        $avgLoss = $loss / $period;
        $out[$period] = self::rsiFrom($avgGain, $avgLoss);

        for ($i = $period + 1; $i < $count; $i++) {
            $change = $closes[$i] - $closes[$i - 1];
            $up = $change > 0 ? $change : 0.0;
            $down = $change < 0 ? -$change : 0.0;

            $avgGain = (($avgGain * ($period - 1)) + $up) / $period;
            $avgLoss = (($avgLoss * ($period - 1)) + $down) / $period;

            $out[$i] = self::rsiFrom($avgGain, $avgLoss);
        }

        return $out;
    }

    /**
     * A period with no losses at all is 100 by definition rather than a division by zero.
     */
    private static function rsiFrom(float $avgGain, float $avgLoss): float
    {
        if ($avgLoss == 0.0) {
            return $avgGain == 0.0 ? 50.0 : 100.0;
        }

        return 100.0 - (100.0 / (1.0 + ($avgGain / $avgLoss)));
    }

    /**
     * MACD: the distance between two EMAs, and a smoothing of that distance.
     *
     * ## The signal line is an SMA, and that is deliberate
     *
     * Appel's original definition smooths the MACD line with an EMA, and most textbooks
     * and most charting libraries follow it. MetaTrader's own `iMACD` uses a **simple**
     * moving average instead, and that is the histogram the people configuring this system
     * are looking at while they do it.
     *
     * This file has already made that choice once - Wilder smoothing for ATR and ADX,
     * because a stop at "1.5 ATR" has to land where the trader's chart says it does. The
     * same reasoning applies to a histogram that has just crossed zero: agreeing with the
     * screen matters more than agreeing with the paper.
     *
     * @param  array<int, float>  $closes
     * @return array{macd: array<int, float|null>, signal: array<int, float|null>, histogram: array<int, float|null>}
     */
    public static function macd(array $closes, int $fast = 12, int $slow = 26, int $signal = 9): array
    {
        self::guardPeriod($fast);
        self::guardPeriod($slow);
        self::guardPeriod($signal);

        if ($fast >= $slow) {
            throw new InvalidArgumentException("MACD fast period must be shorter than slow, got {$fast} and {$slow}.");
        }

        $count = count($closes);
        $fastEma = self::ema($closes, $fast);
        $slowEma = self::ema($closes, $slow);

        $macd = array_fill(0, $count, null);

        for ($i = 0; $i < $count; $i++) {
            if ($fastEma[$i] === null || $slowEma[$i] === null) {
                continue;
            }

            $macd[$i] = $fastEma[$i] - $slowEma[$i];
        }

        // The signal line averages the MACD line, which does not exist through the slow
        // EMA's warm-up. Averaging over the nulls would quietly treat them as zero and
        // drag the first several signal values toward the axis, so the defined tail is
        // averaged on its own and written back at the right offset.
        $defined = array_values(array_filter($macd, fn (?float $v) => $v !== null));
        $signalTail = self::sma($defined, $signal);

        $signalLine = array_fill(0, $count, null);
        $offset = $count - count($defined);

        foreach ($signalTail as $i => $value) {
            $signalLine[$offset + $i] = $value;
        }

        $histogram = array_fill(0, $count, null);

        for ($i = 0; $i < $count; $i++) {
            if ($macd[$i] === null || $signalLine[$i] === null) {
                continue;
            }

            $histogram[$i] = $macd[$i] - $signalLine[$i];
        }

        return ['macd' => $macd, 'signal' => $signalLine, 'histogram' => $histogram];
    }

    /**
     * Stochastic oscillator: where the close sits within the recent high-low range.
     *
     * Defaults are 14/3/3 rather than MetaTrader's own 5/3/3, because the fast default is
     * noise on the timeframes this system trades and every published description of the
     * indicator uses 14. Both are configurable; neither is a signal on its own.
     *
     * `slowing` averages the raw %K before %D averages it again - this is the "slow"
     * stochastic. Setting it to 1 gives the fast one.
     *
     * @param  array<int, float>  $highs
     * @param  array<int, float>  $lows
     * @param  array<int, float>  $closes
     * @return array{k: array<int, float|null>, d: array<int, float|null>}
     */
    public static function stochastic(array $highs, array $lows, array $closes, int $period = 14, int $slowing = 3, int $dPeriod = 3): array
    {
        self::guardSameLength($highs, $lows, $closes);
        self::guardPeriod($period);
        self::guardPeriod($slowing);
        self::guardPeriod($dPeriod);

        $count = count($closes);
        $raw = array_fill(0, $count, null);

        for ($i = $period - 1; $i < $count; $i++) {
            $highest = max(array_slice($highs, $i - $period + 1, $period));
            $lowest = min(array_slice($lows, $i - $period + 1, $period));
            $range = $highest - $lowest;

            // A flat window has no "position within the range". 50 is the honest answer -
            // neither extreme - and it keeps the series continuous through a dead session
            // rather than punching a hole in it.
            $raw[$i] = $range == 0.0 ? 50.0 : (($closes[$i] - $lowest) / $range) * 100.0;
        }

        $k = self::smoothDefined($raw, $slowing);
        $d = self::smoothDefined($k, $dPeriod);

        return ['k' => $k, 'd' => $d];
    }

    /**
     * Bollinger Bands.
     *
     * `bandwidth()` above already computes the width as a comparable ratio, which is what
     * the squeeze factor needs. This returns the bands themselves, because a chart has to
     * draw them and an analysis has to be able to say price is riding the upper one.
     *
     * Population standard deviation over the window, matching `bandwidth()` and matching
     * how the bands are conventionally drawn.
     *
     * @param  array<int, float>  $closes
     * @return array{upper: array<int, float|null>, middle: array<int, float|null>, lower: array<int, float|null>}
     */
    public static function bollinger(array $closes, int $period = 20, float $deviations = 2.0): array
    {
        self::guardPeriod($period);

        $count = count($closes);
        $middle = self::sma($closes, $period);
        $upper = array_fill(0, $count, null);
        $lower = array_fill(0, $count, null);

        for ($i = $period - 1; $i < $count; $i++) {
            $mean = $middle[$i];
            $variance = 0.0;

            foreach (array_slice($closes, $i - $period + 1, $period) as $value) {
                $variance += ($value - $mean) ** 2;
            }

            $sd = sqrt($variance / $period);

            $upper[$i] = $mean + ($deviations * $sd);
            $lower[$i] = $mean - ($deviations * $sd);
        }

        return ['upper' => $upper, 'middle' => $middle, 'lower' => $lower];
    }

    /**
     * Moving-average a series that has nulls at the front, keeping index alignment.
     *
     * Used by the stochastic, where %K smooths raw %K and %D smooths %K again. Averaging
     * across the leading nulls would read them as zero and pull the first values of every
     * smoothed series toward the floor.
     *
     * @param  array<int, float|null>  $series
     * @return array<int, float|null>
     */
    private static function smoothDefined(array $series, int $period): array
    {
        if ($period === 1) {
            return $series;
        }

        $count = count($series);
        $defined = array_values(array_filter($series, fn (?float $v) => $v !== null));

        if ($defined === []) {
            return array_fill(0, $count, null);
        }

        $smoothed = self::sma($defined, $period);
        $out = array_fill(0, $count, null);
        $offset = $count - count($defined);

        foreach ($smoothed as $i => $value) {
            $out[$offset + $i] = $value;
        }

        return $out;
    }

    public static function last(array $series): ?float
    {
        for ($i = count($series) - 1; $i >= 0; $i--) {
            if ($series[$i] !== null) {
                return $series[$i];
            }
        }

        return null;
    }

    private static function guardPeriod(int $period): void
    {
        if ($period < 1) {
            throw new InvalidArgumentException("Indicator period must be at least 1, got {$period}.");
        }
    }

    private static function guardSameLength(array ...$series): void
    {
        $lengths = array_unique(array_map('count', $series));

        if (count($lengths) > 1) {
            throw new InvalidArgumentException('OHLC series must all be the same length.');
        }
    }
}
