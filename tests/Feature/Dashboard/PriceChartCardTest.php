<?php

namespace Tests\Feature\Dashboard;

use App\Livewire\Dashboard\PriceChartCard;
use App\Models\BrokerAccount;
use App\Models\Candle;
use App\Models\Strategy;
use App\Models\Trade;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The price chart and its position overlays.
 *
 * The candles are the easy half. What these pin down is the levels: that a nullable
 * target is drawn as absent rather than as a line at zero, and that closed positions stop
 * being drawn - a stale stop line on a live chart is worse than no chart.
 */
class PriceChartCardTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private BrokerAccount $account;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->account = BrokerAccount::create([
            'user_id' => $this->user->id,
            'label' => 'Elev8 Demo',
            'broker_name' => 'Elev8',
            'account_number' => '230070844',
            'server' => 'Elev8-Demo2',
            'is_demo' => true,
            'is_active' => true,
            'account_currency' => 'USD',
            'leverage' => 1000,
        ]);

        $this->actingAs($this->user);
    }

    private function bars(int $count = 20): void
    {
        for ($i = $count; $i > 0; $i--) {
            Candle::create([
                'user_id' => $this->user->id,
                'broker_account_id' => $this->account->id,
                'symbol' => 'XAUUSD',
                'timeframe' => 'M5',
                'open_time' => now()->subMinutes(5 * $i),
                'open' => 2000, 'high' => 2005, 'low' => 1995, 'close' => 2002,
            ]);
        }
    }

    private function trade(array $overrides = []): Trade
    {
        return Trade::create($overrides + [
            'user_id' => $this->user->id,
            'strategy_id' => Strategy::where('user_id', $this->user->id)->value('id'),
            'broker_account_id' => $this->account->id,
            'mt5_ticket' => 555001,
            'origin' => 'bot',
            'symbol' => 'XAUUSD',
            'direction' => 'buy',
            'initial_lot_size' => 0.01,
            'remaining_lot_size' => 0.01,
            'entry_price' => 2000.00,
            'sl_price' => 1997.00,
            'tp1_price' => 2003.00,
            'tp2_price' => 2010.00,
            'tp3_price' => 2020.00,
            'status' => 'open',
            'opened_at' => now()->subMinutes(20),
        ]);
    }

    public function test_it_reports_no_data_rather_than_an_empty_chart(): void
    {
        Livewire::test(PriceChartCard::class)
            ->assertSet('hasData', false)
            ->assertSee('No M5 bars yet');
    }

    public function test_it_serialises_candles_for_the_chart(): void
    {
        $this->bars();

        $candles = Livewire::test(PriceChartCard::class)
            ->assertSet('hasData', true)
            ->get('candles');

        $this->assertCount(20, $candles);
        // Lightweight Charts wants a UTC epoch in seconds, not a formatted string.
        $this->assertIsInt($candles[0]['time']);
        $this->assertSame(2002.0, $candles[0]['close']);
    }

    public function test_an_open_position_draws_entry_stop_and_the_whole_ladder(): void
    {
        $this->bars();
        $this->trade();

        $levels = Livewire::test(PriceChartCard::class)->get('levels');
        $titles = array_column($levels, 'title');

        $this->assertContains('Entry #555001', $titles);
        $this->assertContains('SL #555001', $titles);
        $this->assertContains('TP1 #555001', $titles);
        $this->assertContains('TP2 #555001', $titles);
        $this->assertContains('TP3 #555001', $titles);
    }

    /**
     * The nullable-target case the schema was changed for.
     */
    public function test_an_absent_target_is_not_drawn_at_zero(): void
    {
        $this->bars();
        $this->trade(['tp2_price' => null, 'tp3_price' => null]);

        $levels = Livewire::test(PriceChartCard::class)->get('levels');
        $titles = array_column($levels, 'title');

        $this->assertContains('TP1 #555001', $titles);
        $this->assertNotContains('TP2 #555001', $titles);
        $this->assertNotContains('TP3 #555001', $titles);

        foreach ($levels as $level) {
            $this->assertGreaterThan(0.0, $level['price'], 'A null target must be absent, not a line at zero.');
        }
    }

    public function test_a_position_running_on_a_trailing_stop_still_draws_its_stop(): void
    {
        $this->bars();
        $this->trade(['tp1_price' => null, 'tp2_price' => null, 'tp3_price' => null]);

        $titles = array_column(Livewire::test(PriceChartCard::class)->get('levels'), 'title');

        $this->assertContains('SL #555001', $titles);
        $this->assertContains('Entry #555001', $titles);
    }

    public function test_closed_positions_are_not_drawn(): void
    {
        $this->bars();
        $this->trade(['status' => 'closed', 'closed_at' => now()]);

        $this->assertSame([], Livewire::test(PriceChartCard::class)->get('levels'));
        $this->assertSame([], Livewire::test(PriceChartCard::class)->get('markers'));
    }

    public function test_a_partially_closed_position_is_still_drawn(): void
    {
        $this->bars();
        $this->trade(['status' => 'partially_closed', 'remaining_lot_size' => 0.005]);

        $this->assertNotEmpty(Livewire::test(PriceChartCard::class)->get('levels'));
    }

    public function test_the_entry_marker_points_the_way_the_trade_went(): void
    {
        $this->bars();
        $this->trade(['direction' => 'sell']);

        $markers = Livewire::test(PriceChartCard::class)->get('markers');

        $this->assertSame('arrowDown', $markers[0]['shape']);
        $this->assertSame('aboveBar', $markers[0]['position']);
    }

    public function test_the_live_trades_page_renders_the_chart(): void
    {
        $this->bars();

        $this->get(route('trades.live'))
            ->assertOk()
            ->assertSee('x-data="priceChart"', false);
    }
}
