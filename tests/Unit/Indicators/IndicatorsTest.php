<?php

namespace Tests\Unit\Indicators;

use App\Services\Indicators\Indicators;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Indicator maths.
 *
 * These are the numbers the stop distance and the ADX gate are derived from, so they are
 * checked against values worked out by hand rather than against a previous run of this
 * code. A regression here does not throw - it moves every stop in the system slightly,
 * which is the kind of bug that is only ever noticed in the P&L.
 */
class IndicatorsTest extends TestCase
{
    // =====================================================================
    // EMA
    // =====================================================================

    /**
     * With values 1..10 and period 3, the SMA seed is 2 at index 2 and alpha is 0.5.
     * Each later bar is then the midpoint of the new value and the previous EMA, which
     * on a series rising by one per bar settles exactly on the index: 3, 4, 5, ...
     */
    public function test_ema_seeds_on_the_simple_average_and_then_smooths(): void
    {
        $ema = Indicators::ema([1, 2, 3, 4, 5, 6, 7, 8, 9, 10], 3);

        $this->assertNull($ema[0]);
        $this->assertNull($ema[1]);
        $this->assertEqualsWithDelta(2.0, $ema[2], 1e-9);
        $this->assertEqualsWithDelta(3.0, $ema[3], 1e-9);
        $this->assertEqualsWithDelta(4.0, $ema[4], 1e-9);
        $this->assertEqualsWithDelta(9.0, $ema[9], 1e-9);
    }

    public function test_ema_returns_all_nulls_when_the_series_is_shorter_than_the_period(): void
    {
        $ema = Indicators::ema([1, 2], 5);

        $this->assertCount(2, $ema);
        $this->assertSame([null, null], $ema);
    }

    public function test_ema_output_is_index_aligned_with_its_input(): void
    {
        $values = range(1, 40);

        $this->assertCount(count($values), Indicators::ema($values, 20));
    }

    // =====================================================================
    // TRUE RANGE / ATR
    // =====================================================================

    /**
     * The first bar has no previous close, so it has no true range. Using its own
     * high-low there would understate the ATR seed for the whole series.
     */
    public function test_true_range_is_undefined_on_the_first_bar(): void
    {
        $tr = Indicators::trueRange([10, 11], [9, 10], [9.5, 10.5]);

        $this->assertNull($tr[0]);
        $this->assertNotNull($tr[1]);
    }

    /**
     * A gap counts. Bar 1 spans 10 to 9 but opens well above the previous close of 5,
     * so the true range is 10 - 5 = 5, not the 1 point of visible bar height.
     */
    public function test_true_range_measures_from_the_previous_close_across_a_gap(): void
    {
        $tr = Indicators::trueRange([6, 10], [4, 9], [5, 9.5]);

        $this->assertEqualsWithDelta(5.0, $tr[1], 1e-9);
    }

    /**
     * Every bar is identical: 2 points tall, closing in the middle. True range is 2 on
     * every bar, so any amount of Wilder smoothing must still return exactly 2.
     */
    public function test_atr_on_a_constant_range_series_is_that_range(): void
    {
        $count = 40;
        $highs = array_fill(0, $count, 102.0);
        $lows = array_fill(0, $count, 100.0);
        $closes = array_fill(0, $count, 101.0);

        $atr = Indicators::atr($highs, $lows, $closes, 14);

        // True range starts at index 1, so a 14-period ATR first reads at index 14.
        $this->assertNull($atr[13]);
        $this->assertEqualsWithDelta(2.0, $atr[14], 1e-9);
        $this->assertEqualsWithDelta(2.0, $atr[39], 1e-9);
    }

    public function test_atr_needs_one_more_bar_than_its_period(): void
    {
        $highs = array_fill(0, 14, 102.0);
        $lows = array_fill(0, 14, 100.0);
        $closes = array_fill(0, 14, 101.0);

        $this->assertSame([], array_filter(Indicators::atr($highs, $lows, $closes, 14), fn ($v) => $v !== null));
    }

    // =====================================================================
    // ADX
    // =====================================================================

    /**
     * A flat market has no directional movement at all, so both DIs are zero and ADX
     * reads zero. This is the case the ADX gate exists to reject, and it must not
     * divide by zero on the way to saying so.
     */
    public function test_adx_is_zero_on_a_flat_series(): void
    {
        $count = 60;
        $highs = array_fill(0, $count, 102.0);
        $lows = array_fill(0, $count, 100.0);
        $closes = array_fill(0, $count, 101.0);

        $result = Indicators::adx($highs, $lows, $closes, 14);

        $this->assertEqualsWithDelta(0.0, Indicators::last($result['adx']), 1e-9);
        $this->assertEqualsWithDelta(0.0, Indicators::last($result['plus_di']), 1e-9);
    }

    /**
     * A perfectly ordered advance - every high and every low one point above the last -
     * has +DM on every bar and -DM on none, so DX pins at 100 and ADX converges there.
     */
    public function test_adx_saturates_on_a_perfect_uptrend(): void
    {
        $highs = $lows = $closes = [];

        for ($i = 0; $i < 60; $i++) {
            $highs[] = $i + 1.0;
            $lows[] = $i + 0.0;
            $closes[] = $i + 0.5;
        }

        $result = Indicators::adx($highs, $lows, $closes, 14);

        $this->assertEqualsWithDelta(100.0, Indicators::last($result['adx']), 1e-6);
        $this->assertEqualsWithDelta(0.0, Indicators::last($result['minus_di']), 1e-9);
        $this->assertGreaterThan(0.0, Indicators::last($result['plus_di']));
    }

    /**
     * The mirror case: a perfect decline must read as an equally strong trend. ADX is a
     * strength measure and says nothing about direction - which is exactly why the
     * strategy takes direction from the EMAs and uses ADX only as a gate.
     */
    public function test_adx_reads_the_same_strength_on_a_perfect_downtrend(): void
    {
        $highs = $lows = $closes = [];

        for ($i = 0; $i < 60; $i++) {
            $highs[] = 100.0 - $i;
            $lows[] = 99.0 - $i;
            $closes[] = 99.5 - $i;
        }

        $result = Indicators::adx($highs, $lows, $closes, 14);

        $this->assertEqualsWithDelta(100.0, Indicators::last($result['adx']), 1e-6);
        $this->assertEqualsWithDelta(0.0, Indicators::last($result['plus_di']), 1e-9);
    }

    /**
     * ADX smooths DX over a second full period, so it cannot read until 2 * period - 1.
     * A 14-period ADX on 27 bars has nothing to say, and must say so with nulls rather
     * than with a half-warmed number.
     */
    public function test_adx_warm_up_spans_two_periods(): void
    {
        $highs = $lows = $closes = [];

        for ($i = 0; $i < 28; $i++) {
            $highs[] = $i + 1.0;
            $lows[] = $i + 0.0;
            $closes[] = $i + 0.5;
        }

        $result = Indicators::adx($highs, $lows, $closes, 14);

        $this->assertNull($result['adx'][26]);
        $this->assertNotNull($result['adx'][27]);
    }

    // =====================================================================
    // SMA
    // =====================================================================

    public function test_sma_averages_its_window_and_warms_up_like_everything_else(): void
    {
        $sma = Indicators::sma([1, 2, 3, 4, 5], 3);

        $this->assertSame([null, null], array_slice($sma, 0, 2));
        $this->assertEqualsWithDelta(2.0, $sma[2], 1e-9);
        $this->assertEqualsWithDelta(3.0, $sma[3], 1e-9);
        $this->assertEqualsWithDelta(4.0, $sma[4], 1e-9);
    }

    /**
     * The running total is maintained incrementally rather than re-summing each window, so
     * this checks it has not drifted over a long series - the failure mode of that
     * optimisation is a slow accumulation of float error, not a wrong first value.
     */
    public function test_sma_does_not_drift_over_a_long_series(): void
    {
        $values = range(1, 500);
        $sma = Indicators::sma($values, 200);

        // Window 301..500 has mean 400.5.
        $this->assertEqualsWithDelta(400.5, $sma[499], 1e-9);
    }

    // =====================================================================
    // RSI
    // =====================================================================

    /**
     * Wilder's own worked example from *New Concepts in Technical Trading Systems*.
     *
     * These two numbers are the reason to prefer a published fixture over a snapshot of
     * this code: they are independent of the implementation, so a refactor that changes
     * the smoothing fails here instead of silently moving every momentum reading.
     */
    public function test_rsi_matches_wilders_published_worked_example(): void
    {
        $closes = [
            44.34, 44.09, 44.15, 43.61, 44.33, 44.83, 45.10, 45.42, 45.84, 46.08,
            45.89, 46.03, 45.61, 46.28, 46.28, 46.00, 46.03, 46.41, 46.22, 45.64,
        ];

        $rsi = Indicators::rsi($closes, 14);

        $this->assertEqualsWithDelta(70.46, $rsi[14], 0.01);
        $this->assertEqualsWithDelta(66.25, $rsi[15], 0.01);
    }

    /**
     * The first close has no change before it, so the first defined RSI is at index
     * `period`, not `period - 1`. Getting this wrong reads momentum from the wrong bar.
     */
    public function test_rsi_warms_up_one_bar_later_than_a_moving_average(): void
    {
        $rsi = Indicators::rsi(range(1, 30), 14);

        $this->assertNull($rsi[13]);
        $this->assertNotNull($rsi[14]);
        $this->assertCount(30, $rsi);
    }

    public function test_rsi_is_one_hundred_when_nothing_has_fallen(): void
    {
        // Not a division by zero: a period with no losses genuinely has infinite relative
        // strength, and 100 is what that maps to.
        $rsi = Indicators::rsi(range(1, 30), 14);

        $this->assertEqualsWithDelta(100.0, $rsi[14], 1e-9);
    }

    public function test_rsi_is_zero_when_nothing_has_risen(): void
    {
        $rsi = Indicators::rsi(array_reverse(range(1, 30)), 14);

        $this->assertEqualsWithDelta(0.0, $rsi[14], 1e-9);
    }

    public function test_rsi_of_a_flat_series_is_neither_extreme(): void
    {
        $rsi = Indicators::rsi(array_fill(0, 30, 5.0), 14);

        $this->assertEqualsWithDelta(50.0, $rsi[14], 1e-9);
    }

    // =====================================================================
    // MACD
    // =====================================================================

    /**
     * On a series rising by exactly one per bar, both EMAs settle into a constant lag and
     * their difference stops moving - so the signal line converges onto the MACD line and
     * the histogram goes to zero. Any residual histogram means one of the two EMAs is
     * still drifting, which is what a seeding bug looks like.
     */
    public function test_macd_histogram_settles_to_zero_on_a_constant_slope(): void
    {
        $result = Indicators::macd(range(1, 120));

        $this->assertEqualsWithDelta(0.0, $result['histogram'][119], 1e-6);
        $this->assertEqualsWithDelta(7.0, $result['macd'][119], 1e-6);
    }

    /**
     * The signal line averages the MACD line, which does not exist through the slow EMA's
     * warm-up. If those nulls were averaged as zeros the first signal values would be
     * dragged toward the axis - so the offsets are pinned here explicitly.
     */
    public function test_macd_and_its_signal_line_warm_up_at_the_right_bars(): void
    {
        $result = Indicators::macd(range(1, 60), 12, 26, 9);

        $this->assertNull($result['macd'][24]);
        $this->assertNotNull($result['macd'][25]);

        $this->assertNull($result['signal'][32]);
        $this->assertNotNull($result['signal'][33]);

        $this->assertCount(60, $result['macd']);
        $this->assertCount(60, $result['signal']);
        $this->assertCount(60, $result['histogram']);
    }

    public function test_macd_rejects_a_fast_period_that_is_not_faster(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Indicators::macd(range(1, 60), 26, 12);
    }

    // =====================================================================
    // STOCHASTIC
    // =====================================================================

    public function test_stochastic_reads_one_hundred_at_the_top_of_the_range(): void
    {
        $highs = array_fill(0, 20, 10.0);
        $lows = array_fill(0, 20, 0.0);
        $closes = array_fill(0, 20, 10.0);

        $result = Indicators::stochastic($highs, $lows, $closes, 14, 1, 1);

        $this->assertEqualsWithDelta(100.0, $result['k'][19], 1e-9);
    }

    public function test_stochastic_reads_zero_at_the_bottom_of_the_range(): void
    {
        $highs = array_fill(0, 20, 10.0);
        $lows = array_fill(0, 20, 0.0);
        $closes = array_fill(0, 20, 0.0);

        $result = Indicators::stochastic($highs, $lows, $closes, 14, 1, 1);

        $this->assertEqualsWithDelta(0.0, $result['k'][19], 1e-9);
    }

    /**
     * A window whose high equals its low has no range for the close to sit inside. 50 is
     * the honest reading - neither extreme - and it keeps the series continuous through a
     * dead session instead of punching a null into the middle of it.
     */
    public function test_a_flat_window_reads_mid_range_rather_than_dividing_by_zero(): void
    {
        $flat = array_fill(0, 20, 7.0);

        $result = Indicators::stochastic($flat, $flat, $flat, 14, 1, 1);

        $this->assertEqualsWithDelta(50.0, $result['k'][19], 1e-9);
    }

    public function test_stochastic_smoothing_does_not_average_across_the_warm_up(): void
    {
        $highs = array_fill(0, 30, 10.0);
        $lows = array_fill(0, 30, 0.0);
        $closes = array_fill(0, 30, 10.0);

        $result = Indicators::stochastic($highs, $lows, $closes, 14, 3, 3);

        // Raw %K is 100 from bar 13 onward. Smoothed twice by 3, the first fully-formed
        // %D is still exactly 100 - not something less, which is what averaging leading
        // nulls as zeros would produce.
        $this->assertEqualsWithDelta(100.0, $result['d'][29], 1e-9);
        $this->assertNull($result['k'][12]);
    }

    // =====================================================================
    // BOLLINGER BANDS
    // =====================================================================

    public function test_bollinger_bands_sit_symmetrically_around_the_simple_average(): void
    {
        $closes = [2, 4, 4, 4, 4, 4, 4, 4];

        $result = Indicators::bollinger($closes, 8, 2.0);

        // Mean 3.75, population SD 0.661437...
        $this->assertEqualsWithDelta(3.75, $result['middle'][7], 1e-9);
        $this->assertEqualsWithDelta(5.072876, $result['upper'][7], 1e-5);
        $this->assertEqualsWithDelta(2.427124, $result['lower'][7], 1e-5);
    }

    public function test_bollinger_bands_collapse_onto_the_mean_with_no_variance(): void
    {
        $result = Indicators::bollinger(array_fill(0, 25, 6.0), 20);

        $this->assertEqualsWithDelta(6.0, $result['upper'][24], 1e-9);
        $this->assertEqualsWithDelta(6.0, $result['lower'][24], 1e-9);
    }

    /**
     * `bandwidth()` already existed and is used by the squeeze factor. The bands added
     * later must describe the same distribution, or the chart draws one thing and the
     * gate measures another.
     */
    public function test_the_bands_agree_with_the_bandwidth_already_used_by_the_squeeze(): void
    {
        $closes = [];

        for ($i = 0; $i < 40; $i++) {
            $closes[] = 100 + sin($i / 3) * 5;
        }

        $bands = Indicators::bollinger($closes, 20, 2.0);
        $bandwidth = Indicators::bandwidth($closes, 20, 2.0);

        $width = $bands['upper'][39] - $bands['lower'][39];

        $this->assertEqualsWithDelta($bandwidth[39], $width / abs($bands['middle'][39]), 1e-9);
    }

    // =====================================================================
    // GUARDS
    // =====================================================================

    public function test_mismatched_series_lengths_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Indicators::atr([1, 2, 3], [1, 2], [1, 2, 3], 2);
    }

    public function test_a_period_below_one_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Indicators::ema([1, 2, 3], 0);
    }

    public function test_last_returns_null_when_nothing_warmed_up(): void
    {
        $this->assertNull(Indicators::last([null, null]));
        $this->assertEqualsWithDelta(3.0, Indicators::last([1.0, 3.0, null]), 1e-9);
    }
}
