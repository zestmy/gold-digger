<?php

namespace Tests\Feature\Pages;

use App\Livewire\Pages\LiveTrades;
use App\Livewire\Pages\Signals as SignalsPage;
use App\Models\BotHeartbeat;
use App\Models\BrokerAccount;
use App\Models\Candle;
use App\Models\Signal;
use App\Models\Strategy;
use App\Models\Trade;
use App\Models\TradeCommand;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The screens that surface the strategy layer.
 *
 * The Live Trades tests matter most. Those buttons used to update `trades` directly, so the
 * dashboard showed a position closed while it was still open at the broker - the most
 * misleading state this application can be in, and one a user would only discover from their
 * account balance.
 */
class SignalsAndTradesPageTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private BrokerAccount $account;

    private Strategy $strategy;

    private const SYMBOL = 'XAUUSDm';

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        $this->account = BrokerAccount::create([
            'user_id' => $this->user->id,
            'label' => 'Elev8 Demo',
            'broker_name' => 'Elev8',
            'account_number' => '12345678',
            'server' => 'Elev8-Demo',
            'is_demo' => true,
            'is_active' => true,
        ]);

        $this->strategy = Strategy::where('user_id', $this->user->id)->firstOrFail();

        $this->actingAs($this->user);
    }

    private function signal(array $overrides = []): Signal
    {
        static $bar = 0;
        $bar++;

        return Signal::create(array_merge([
            'strategy_id' => $this->strategy->id,
            'symbol' => self::SYMBOL,
            'timeframe' => 'M5',
            'direction' => 'buy',
            'entry_price' => 2000.00,
            'sl_price' => 1995.00,
            'tp1_price' => 2003.00,
            'tp2_price' => 2010.00,
            'tp3_price' => 2020.00,
            'suggested_lot_size' => 0.10,
            'features' => ['adx' => 30.5, 'atr' => 3.2, 'sl_pips' => 48, 'trend_direction' => 'buy'],
            'was_executed' => false,
            'generated_at' => Carbon::parse('2026-03-10 13:00:00', 'UTC')->addMinutes($bar * 5),
        ], $overrides));
    }

    private function openTrade(array $overrides = []): Trade
    {
        return Trade::create(array_merge([
            'user_id' => $this->user->id,
            'strategy_id' => $this->strategy->id,
            'broker_account_id' => $this->account->id,
            'mt5_ticket' => 810001,
            'origin' => 'bot',
            'symbol' => self::SYMBOL,
            'direction' => 'buy',
            'initial_lot_size' => 0.50,
            'remaining_lot_size' => 0.50,
            'entry_price' => 2000.00,
            'sl_price' => 1995.00,
            'tp1_price' => 2003.00,
            'tp2_price' => 2010.00,
            'tp3_price' => 2020.00,
            'status' => 'open',
            'opened_at' => now()->subHour(),
        ], $overrides));
    }

    // =====================================================================
    // LIVE TRADES - CLOSING MUST NOT LIE
    // =====================================================================

    /**
     * The whole point of the change. Pressing Close asks the terminal; it does not decide
     * the position is gone.
     */
    public function test_closing_a_position_queues_a_command_and_leaves_the_row_open(): void
    {
        $trade = $this->openTrade();

        Livewire::test(LiveTrades::class)->call('closeTrade', $trade->id);

        $command = TradeCommand::where('type', 'close')->firstOrFail();

        $this->assertSame($trade->mt5_ticket, $command->payload['ticket']);
        $this->assertEqualsWithDelta(0.50, (float) $command->payload['volume'], 1e-9);
        $this->assertSame('manual', $command->payload['reason']);

        // Still open, because the broker has not said otherwise.
        $this->assertSame('open', $trade->fresh()->status);
    }

    public function test_closing_twice_queues_one_command(): void
    {
        $trade = $this->openTrade();

        Livewire::test(LiveTrades::class)
            ->call('closeTrade', $trade->id)
            ->call('closeTrade', $trade->id);

        $this->assertSame(1, TradeCommand::where('type', 'close')->count());
    }

    public function test_close_all_queues_a_flatten_and_closes_nothing_locally(): void
    {
        $trade = $this->openTrade();

        Livewire::test(LiveTrades::class)->call('closeAllTrades');

        $this->assertSame(1, TradeCommand::where('type', 'close_all')->count());
        $this->assertSame('open', $trade->fresh()->status);
    }

    public function test_a_position_with_a_queued_close_is_shown_as_closing(): void
    {
        $trade = $this->openTrade();

        Livewire::test(LiveTrades::class)
            ->call('closeTrade', $trade->id)
            ->assertSee('closing');
    }

    public function test_another_users_position_cannot_be_closed(): void
    {
        $other = User::factory()->create();
        $otherStrategy = Strategy::where('user_id', $other->id)->firstOrFail();

        $trade = $this->openTrade([
            'user_id' => $other->id,
            'strategy_id' => $otherStrategy->id,
            'mt5_ticket' => 810099,
        ]);

        $this->expectException(ModelNotFoundException::class);

        try {
            Livewire::test(LiveTrades::class)->call('closeTrade', $trade->id);
        } finally {
            // The guard has to stop the command, not merely the response.
            $this->assertSame(0, TradeCommand::count());
        }
    }

    /**
     * An adopted position has no ladder and may have no stop. The row must say so rather
     * than rendering a stop at 0.00.
     */
    public function test_an_adopted_position_is_labelled_and_shows_no_invented_stop(): void
    {
        $this->openTrade([
            'mt5_ticket' => 810002,
            'origin' => 'adopted',
            'sl_price' => null,
            'tp1_price' => null,
            'tp2_price' => null,
            'tp3_price' => null,
        ]);

        Livewire::test(LiveTrades::class)
            ->assertSee('ADOPTED')
            // The stop cell says so rather than rendering a level the position lacks.
            ->assertSee('This position has no stop loss set at the broker.')
            ->assertSee('none');
    }

    // =====================================================================
    // THE ROUTES THEMSELVES
    // =====================================================================

    /**
     * Livewire::test renders the component alone. These go through the router and the real
     * layout, so a broken nav link or a missing route name fails here rather than in a
     * browser.
     */
    public function test_the_signals_route_renders(): void
    {
        $this->signal();

        $this->get(route('signals'))
            ->assertOk()
            ->assertSee('Signals')
            ->assertSee('Price feed');
    }

    public function test_the_live_trades_route_renders(): void
    {
        $this->openTrade();

        $this->get(route('trades.live'))->assertOk();
    }

    public function test_the_dashboard_renders_with_the_new_status_card(): void
    {
        $this->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Newest bar');
    }

    // =====================================================================
    // SIGNALS PAGE
    // =====================================================================

    public function test_the_signals_page_lists_signals(): void
    {
        $this->signal();

        Livewire::test(SignalsPage::class)
            ->assertOk()
            ->assertSee('BUY')
            ->assertSee('2,000.00');
    }

    /**
     * A skip reason is the content of the row, and the raw enum value means nothing to a
     * person. The page has to translate it.
     */
    public function test_a_skip_reason_is_shown_in_plain_language(): void
    {
        $this->signal(['skip_reason' => 'adx_below_threshold']);

        Livewire::test(SignalsPage::class)
            ->assertSee('Trend too weak')
            // The help text is what makes the label actionable.
            ->assertSee('Lower it to take more of these');
    }

    public function test_signals_can_be_filtered_by_reason(): void
    {
        $this->signal(['skip_reason' => 'adx_below_threshold']);
        $this->signal(['skip_reason' => 'session_closed']);

        // Asserted on the returned rows, not the rendered text: the filter chips list every
        // reason by design, so both labels are on the page whichever filter is active.
        $rows = Livewire::test(SignalsPage::class)
            ->set('filter', 'session_closed')
            ->viewData('signals');

        $this->assertCount(1, $rows);
        $this->assertSame('session_closed', $rows->first()->skip_reason);
    }

    public function test_acted_on_signals_can_be_isolated(): void
    {
        $this->signal(['skip_reason' => 'bot_inactive']);
        $this->signal();

        $rows = Livewire::test(SignalsPage::class)
            ->set('filter', 'taken')
            ->viewData('signals');

        $this->assertCount(1, $rows);
        $this->assertNull($rows->first()->skip_reason);
    }

    /**
     * An accepted signal with no fill yet is a real state - the command can still expire or
     * be rejected - and must not look like a gap in the record.
     */
    public function test_an_accepted_signal_awaiting_its_fill_reads_as_in_flight(): void
    {
        $this->signal();

        Livewire::test(SignalsPage::class)->assertSee('In flight');
    }

    public function test_a_traded_signal_links_to_its_trade(): void
    {
        $trade = $this->openTrade();

        $this->signal(['was_executed' => true, 'resulting_trade_id' => $trade->id]);

        Livewire::test(SignalsPage::class)
            ->assertSee('Traded')
            ->assertSee((string) $trade->mt5_ticket);
    }

    /**
     * If bars have stopped arriving, nothing downstream can work and every other
     * explanation on the page is a red herring. That has to be the first thing shown.
     */
    public function test_a_missing_price_feed_is_called_out(): void
    {
        BotHeartbeat::create([
            'user_id' => $this->user->id,
            'broker_account_id' => $this->account->id,
            'source' => 'mql5_ea',
            'resolved_symbol' => self::SYMBOL,
            'last_seen_at' => now(),
        ]);

        Livewire::test(SignalsPage::class)->assertSee('No bars have ever arrived');
    }

    public function test_a_short_series_is_reported_as_still_warming_up(): void
    {
        BotHeartbeat::create([
            'user_id' => $this->user->id,
            'broker_account_id' => $this->account->id,
            'source' => 'mql5_ea',
            'resolved_symbol' => self::SYMBOL,
            'last_seen_at' => now(),
        ]);

        // Well short of the ~100 bars the indicators need before they read at all.
        for ($i = 0; $i < 5; $i++) {
            Candle::create([
                'user_id' => $this->user->id,
                'broker_account_id' => $this->account->id,
                'symbol' => self::SYMBOL,
                'timeframe' => 'M5',
                'open_time' => now()->subMinutes(5 * $i),
                'open' => 2000,
                'high' => 2001,
                'low' => 1999,
                'close' => 2000,
            ]);
        }

        Livewire::test(SignalsPage::class)
            ->assertSee('WARMING UP')
            ->assertDontSee('READY');
    }

    public function test_the_signals_page_only_shows_this_users_signals(): void
    {
        $other = User::factory()->create();
        $otherStrategy = Strategy::where('user_id', $other->id)->firstOrFail();

        Signal::create([
            'strategy_id' => $otherStrategy->id,
            'symbol' => 'EURUSD',
            'timeframe' => 'M5',
            'direction' => 'sell',
            'entry_price' => 1.1,
            'sl_price' => 1.2,
            'generated_at' => now(),
        ]);

        Livewire::test(SignalsPage::class)->assertDontSee('EURUSD');
    }
}
