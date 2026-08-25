<?php

namespace Tests\Feature\Strategy;

use App\Services\Indicators\Indicators;
use Tests\TestCase;

/**
 * Volatility compression, the "pre-mover" input.
 *
 * The claim being tested is narrow: bands have narrowed relative to this instrument's own
 * recent range. It says a move is more likely and nothing at all about which way, which is
 * exactly the mistake the measurement invites.
 */
class SqueezeTest extends TestCase
{
    public function test_bandwidth_is_a_ratio_so_it_survives_a_change_of_price_level(): void
    {
        $low = Indicators::bandwidth($this->wobble(100.0, 1.0, 60));
        $high = Indicators::bandwidth($this->wobble(4600.0, 46.0, 60));

        // The same proportional wobble at 100 and at 4600. A raw width would differ by 46
        // times; the ratio should barely move, which is what makes it comparable at all.
        $this->assertEqualsWithDelta(
            Indicators::last($low),
            Indicators::last($high),
            0.001,
        );
    }

    public function test_a_quietening_market_registers_as_squeezed(): void
    {
        // Wide for a long while, then tight.
        $closes = array_merge($this->wobble(2650.0, 20.0, 150), $this->wobble(2650.0, 1.0, 30));

        $this->assertTrue(Indicators::squeeze($closes)['squeezed']);
    }

    public function test_an_expanding_market_does_not(): void
    {
        $closes = array_merge($this->wobble(2650.0, 1.0, 150), $this->wobble(2650.0, 20.0, 30));

        $this->assertFalse(Indicators::squeeze($closes)['squeezed']);
    }

    /**
     * Not enough history is not the same as not squeezed.
     */
    public function test_too_little_history_reports_no_threshold_rather_than_a_verdict(): void
    {
        $result = Indicators::squeeze($this->wobble(2650.0, 5.0, 25));

        $this->assertNull($result['threshold']);
        $this->assertFalse($result['squeezed'], 'unknown must not count as squeezed');
    }

    /**
     * @return array<int, float>
     */
    private function wobble(float $base, float $amplitude, int $count): array
    {
        $out = [];

        for ($i = 0; $i < $count; $i++) {
            $out[] = $base + $amplitude * sin($i / 3.0);
        }

        return $out;
    }
}
