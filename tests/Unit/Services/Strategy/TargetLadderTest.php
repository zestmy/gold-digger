<?php

namespace Tests\Unit\Services\Strategy;

use App\Models\Strategy;
use App\Services\Strategy\TargetLadder;
use PHPUnit\Framework\TestCase;

/**
 * The target ladder.
 *
 * One helper places the ladder for the live generator and the backtester, so what these
 * tests pin down is the arithmetic both rely on: rungs in R sit at multiples of the stop,
 * rungs in pips sit where they always did, and the order carries the final rung.
 */
class TargetLadderTest extends TestCase
{
    private TargetLadder $ladder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ladder = new TargetLadder;
    }

    public function test_r_targets_are_multiples_of_the_stop_distance_on_a_buy(): void
    {
        $strategy = $this->strategy(['tp1_r' => 1.0, 'tp2_r' => 2.0, 'tp3_r' => 3.0]);

        $levels = $this->ladder->prices($strategy, entry: 2000.0, sign: 1.0, stopDistance: 5.0, pipSize: 0.10);

        $this->assertSame('r', $levels['unit']);
        $this->assertEqualsWithDelta(2005.0, $levels['tp1_price'], 1e-9);
        $this->assertEqualsWithDelta(2010.0, $levels['tp2_price'], 1e-9);
        $this->assertEqualsWithDelta(2015.0, $levels['tp3_price'], 1e-9);
    }

    public function test_r_targets_sit_below_entry_on_a_sell(): void
    {
        $strategy = $this->strategy(['tp1_r' => 1.0, 'tp2_r' => 2.0, 'tp3_r' => 3.0]);

        $levels = $this->ladder->prices($strategy, entry: 2000.0, sign: -1.0, stopDistance: 5.0, pipSize: 0.10);

        $this->assertEqualsWithDelta(1995.0, $levels['tp1_price'], 1e-9);
        $this->assertEqualsWithDelta(1985.0, $levels['tp3_price'], 1e-9);
    }

    /**
     * The first rung is what the outcome data was about: 1R pays for the stop. The
     * ladder is not allowed to quietly make it less.
     */
    public function test_the_first_rung_pays_at_least_the_stop_when_tp1_r_is_one(): void
    {
        $strategy = $this->strategy(['tp1_r' => 1.0, 'tp2_r' => 2.0, 'tp3_r' => null]);

        $levels = $this->ladder->prices($strategy, entry: 1.10000, sign: 1.0, stopDistance: 0.0012, pipSize: 0.0001);

        $this->assertEqualsWithDelta(0.0012, $levels['tp1_price'] - 1.10000, 1e-9);
    }

    /**
     * The order's own take profit is the final rung, in pips for the wire: 3R of a
     * 5.0 stop is 15.0 in price, 150 pips at 0.10.
     */
    public function test_the_order_carries_the_final_rung_as_pips(): void
    {
        $strategy = $this->strategy(['tp1_r' => 1.0, 'tp2_r' => 2.0, 'tp3_r' => 3.0]);

        $levels = $this->ladder->prices($strategy, entry: 2000.0, sign: 1.0, stopDistance: 5.0, pipSize: 0.10);

        $this->assertEqualsWithDelta(150.0, $levels['order_tp_pips'], 1e-9);
    }

    public function test_a_two_rung_ladder_puts_tp2_on_the_order(): void
    {
        $strategy = $this->strategy(['tp1_r' => 1.0, 'tp2_r' => 2.5, 'tp3_r' => null]);

        $levels = $this->ladder->prices($strategy, entry: 2000.0, sign: 1.0, stopDistance: 4.0, pipSize: 0.10);

        $this->assertNull($levels['tp3_price']);
        $this->assertEqualsWithDelta(100.0, $levels['order_tp_pips'], 1e-9);
    }

    /**
     * R levels are price arithmetic on a price-denominated stop, so they exist before the
     * terminal has said what a pip is. Only the pip figure for the wire waits.
     */
    public function test_r_levels_survive_an_unknown_pip_size_but_the_wire_figure_does_not(): void
    {
        $strategy = $this->strategy(['tp1_r' => 1.0, 'tp2_r' => 2.0, 'tp3_r' => 3.0]);

        $levels = $this->ladder->prices($strategy, entry: 2000.0, sign: 1.0, stopDistance: 5.0, pipSize: null);

        $this->assertEqualsWithDelta(2005.0, $levels['tp1_price'], 1e-9);
        $this->assertNull($levels['order_tp_pips']);
    }

    public function test_pip_targets_apply_when_no_r_is_set(): void
    {
        $strategy = $this->strategy(['tp1_r' => null, 'tp1_pips' => 30, 'tp2_pips' => 100, 'tp3_pips' => 200]);

        $levels = $this->ladder->prices($strategy, entry: 2000.0, sign: 1.0, stopDistance: 5.0, pipSize: 0.10);

        $this->assertSame('pips', $levels['unit']);
        $this->assertEqualsWithDelta(2003.0, $levels['tp1_price'], 1e-9);
        $this->assertEqualsWithDelta(2010.0, $levels['tp2_price'], 1e-9);
        $this->assertEqualsWithDelta(2020.0, $levels['tp3_price'], 1e-9);
        $this->assertEqualsWithDelta(200.0, $levels['order_tp_pips'], 1e-9);
    }

    /**
     * Pips into a price without a pip size is the guess the pip trap punishes; every
     * pip-mode level goes null instead.
     */
    public function test_pip_targets_go_null_without_a_pip_size(): void
    {
        $strategy = $this->strategy(['tp1_r' => null, 'tp1_pips' => 30, 'tp2_pips' => 100, 'tp3_pips' => 200]);

        $levels = $this->ladder->prices($strategy, entry: 2000.0, sign: 1.0, stopDistance: 5.0, pipSize: null);

        $this->assertNull($levels['tp1_price']);
        $this->assertNull($levels['tp3_price']);
        $this->assertNull($levels['order_tp_pips']);
    }

    public function test_a_zero_tp1_r_means_pips_not_a_target_on_top_of_entry(): void
    {
        $strategy = $this->strategy(['tp1_r' => 0.0, 'tp1_pips' => 30, 'tp2_pips' => 100, 'tp3_pips' => null]);

        $this->assertFalse($this->ladder->inR($strategy));
        $this->assertSame('pips', $this->ladder->prices($strategy, 2000.0, 1.0, 5.0, 0.10)['unit']);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function strategy(array $attributes): Strategy
    {
        return new Strategy(array_merge([
            'tp1_pips' => 30, 'tp2_pips' => 100, 'tp3_pips' => 200,
            'tp1_r' => null, 'tp2_r' => null, 'tp3_r' => null,
        ], $attributes));
    }
}
