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
