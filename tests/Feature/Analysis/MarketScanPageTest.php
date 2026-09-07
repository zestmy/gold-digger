<?php

namespace Tests\Feature\Analysis;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Market scan tab as a screen.
 *
 * What the scan measures is ChartAnalysisApiTest's business. This pins the page's place
 * in the menu: under Signals, beside the AI feed and the copier, named for what it does.
 */
class MarketScanPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_route_renders_as_the_market_scan_tab_under_signals(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get(route('analysis'))
            ->assertOk()
            ->assertSee('Signals')
            ->assertSee('Market scan')
            ->assertSee('Nothing on this page places an order');
    }
}
