<?php

namespace Tests\Unit\Services\Trading;

use App\Services\Trading\VolumeRules;
use PHPUnit\Framework\TestCase;

/**
 * The broker's lot grid.
 *
 * One property matters more than the arithmetic: this never returns a size larger than the
 * one it was given. `CFXSExecutor::NormalizeVolume` does - it clamps a sub-minimum size up
 * to the minimum - and that clamp is why a dashboard that left snapping to the terminal was
 * trading more risk than the setting allowed without recording that it had.
 */
class VolumeRulesTest extends TestCase
{
    public function test_a_size_is_snapped_down_onto_the_step(): void
    {
        $this->assertSame(0.03, VolumeRules::snap(0.037, 0.01));
        $this->assertSame(0.03, VolumeRules::snap(0.0399, 0.01));
        $this->assertSame(0.1, VolumeRules::snap(0.19, 0.1));
        $this->assertSame(1.0, VolumeRules::snap(1.4, 0.5));
    }

    /**
     * A step that is not a power of ten still snaps downward. This rounded *up* to 0.2 while
     * the decimals were being inferred from the step's order of magnitude.
     */
    public function test_a_step_that_is_not_a_power_of_ten_still_snaps_down(): void
    {
        $this->assertSame(0.15, VolumeRules::snap(0.1554, 0.15));
        $this->assertSame(0.15, VolumeRules::snap(0.29, 0.15));
        $this->assertSame(0.3, VolumeRules::snap(0.3, 0.15));
        $this->assertSame(0.25, VolumeRules::snap(0.4, 0.25));
    }

    /**
     * 0.03 / 0.01 is 2.9999999999999996 in binary floating point, so a bare floor turns a
     * legal 0.03 into 0.02. The executor carries the same guard for the same reason.
     */
    public function test_a_size_already_on_the_grid_survives_float_dust(): void
    {
        $this->assertSame(0.03, VolumeRules::snap(0.03, 0.01));
        $this->assertSame(0.07, VolumeRules::snap(0.07, 0.01));
        $this->assertSame(0.29, VolumeRules::snap(0.29, 0.01));
    }

    public function test_snapping_never_returns_more_than_it_was_given(): void
    {
        foreach ([0.01, 0.05, 0.15, 0.25, 0.5, 1.0, 2.5] as $step) {
            foreach ([0.001, 0.004, 0.0099, 0.011, 0.1554, 0.4999, 1.2345, 7.77] as $lots) {
                $this->assertLessThanOrEqual($lots, VolumeRules::snap($lots, $step), "step {$step}, lots {$lots}");
            }
        }
    }

    /**
     * The case the whole class exists for. A 0.004-lot size is not raised to the minimum
     * here - it is refused, and the caller records why.
     */
    public function test_a_size_below_the_minimum_is_refused_rather_than_raised(): void
    {
        $this->assertNull(VolumeRules::tradeable(0.004, 0.01, 0.01));
        $this->assertNull(VolumeRules::tradeable(0.009999, 0.01, 0.01));
        $this->assertNull(VolumeRules::tradeable(0.05, 0.01, 0.10));
    }

    public function test_a_tradeable_size_comes_back_on_the_grid(): void
    {
        $this->assertSame(0.01, VolumeRules::tradeable(0.0149, 0.01, 0.01));
        $this->assertSame(0.15, VolumeRules::tradeable(0.1554, 0.01, 0.01));
        $this->assertSame(0.10, VolumeRules::tradeable(0.10, 0.01, 0.10));
    }

    /**
     * A snapped minimum can arrive a hair under itself; refusing it would decline every
     * trade on an account sized exactly to the minimum.
     */
    public function test_a_size_exactly_at_the_minimum_is_tradeable(): void
    {
        $this->assertSame(0.01, VolumeRules::tradeable(0.01, 0.01, 0.01));
        $this->assertSame(0.02, VolumeRules::tradeable(0.02, 0.02, 0.02));
    }

    /**
     * A broker that has not reported its grid gets the retail default rather than no
     * grid at all - unsnapped volume is not a safer answer, it is an unfillable one.
     */
    public function test_an_unreported_grid_falls_back_to_the_retail_default(): void
    {
        $this->assertSame(0.03, VolumeRules::snap(0.037, null));
        $this->assertSame(0.03, VolumeRules::snap(0.037, 0.0));
        $this->assertNull(VolumeRules::tradeable(0.004, null, null));
    }
}
