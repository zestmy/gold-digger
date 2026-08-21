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
