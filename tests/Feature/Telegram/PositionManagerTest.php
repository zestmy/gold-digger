<?php

namespace Tests\Feature\Telegram;

use App\Models\BotHeartbeat;
use App\Models\BotSettings;
use App\Models\BrokerAccount;
use App\Models\Candle;
use App\Models\Strategy;
use App\Models\SymbolSpec;
use App\Models\Trade;
use App\Models\TradeCommand;
use App\Models\User;
use App\Services\Telegram\PositionManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Looking after a copied position once it is open.
 *
 * Until this existed a copied trade had exactly two things minding it: the stop the order
 * carries, and whatever the provider remembers to post. TradeManager had trailed stops for
 * years and selected `origin = 'bot'`, so none of it ever applied.
 *
 * Everything is measured in R because a copied stop is whatever a stranger chose - five
 * points on one signal, forty on the next - and no pip trigger can be right for both.
 */
class PositionManagerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private BotSettings $settings;

    private BrokerAccount $account;

    private Strategy $strategy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->strategy = Strategy::where('user_id', $this->user->id)->firstOrFail();

        $this->account = BrokerAccount::create([
            'user_id' => $this->user->id, 'label' => 'Demo', 'broker_name' => 'Elev8',
            'account_number' => '1', 'server' => 'Elev8-Demo2', 'is_demo' => true, 'is_active' => true,
        ]);

        $this->settings = BotSettings::where('user_id', $this->user->id)->firstOrFail();
        $this->settings->update(['is_active' => true, 'copier_protect_at_r' => 1.00]);

        SymbolSpec::updateOrCreate(
            ['broker_account_id' => $this->account->id, 'symbol' => 'XAUUSD'],
            ['base_symbol' => 'XAUUSD', 'pip_size' => 0.10, 'digits' => 2,
                'pip_value_per_lot' => 10.0, 'volume_min' => 0.01, 'volume_step' => 0.01],
        );

        BotHeartbeat::create([
            'user_id' => $this->user->id, 'broker_account_id' => $this->account->id,
            'source' => 'mql5_ea', 'algo_trading_enabled' => true, 'broker_connected' => true,
            'resolved_symbol' => 'XAUUSD', 'pip_size' => 0.10, 'pip_value_per_lot' => 10.0,
            'volume_min' => 0.01, 'volume_step' => 0.01, 'digits' => 2, 'last_seen_at' => now(),
        ]);
    }

    // =====================================================================
    // THE TRIGGER IS IN R
    // =====================================================================

    public function test_nothing_happens_before_the_trade_has_run_far_enough(): void
    {
        // Entry 2650, stop 2645: one R is 5 points. Best price 2652 is 0.4R.
        $this->trade();
        $this->bars(high: 2652.0);

        $this->assertSame([], (new PositionManager)->manage($this->user));
        $this->assertSame(0, TradeCommand::count());
    }

    public function test_break_even_moves_the_stop_to_entry_at_the_trigger(): void
    {
        $this->settings->update(['copier_breakeven' => true]);

        $this->trade();
        $this->bars(high: 2656.0);   // 1.2R

        $actions = (new PositionManager)->manage($this->user);

        $this->assertContains('break_even', $actions);
        $this->assertEqualsWithDelta(2650.0, TradeCommand::where('type', 'modify')->firstOrFail()->payload['sl_price'], 1e-9);
    }

    /**
     * The same setting has to mean the same thing whatever stop a provider chose.
     */
    public function test_a_wide_stop_needs_a_proportionally_larger_move(): void
    {
        $this->settings->update(['copier_breakeven' => true]);

        // One R is now 40 points, not 5.
        $this->trade(sl: 2610.0);
        $this->bars(high: 2656.0);   // 0.15R - nowhere near

        $this->assertSame([], (new PositionManager)->manage($this->user));
    }

    // =====================================================================
    // TRAILING
    // =====================================================================

    public function test_the_stop_trails_the_best_price_at_the_configured_distance(): void
    {
        $this->settings->update(['copier_trail_distance_r' => 0.5]);

        $this->trade();
        $this->bars(high: 2660.0);   // 2R; half an R behind 2660 is 2657.50

        $actions = (new PositionManager)->manage($this->user);

        $this->assertContains('trail', $actions);
        $this->assertEqualsWithDelta(2657.5, TradeCommand::where('type', 'modify')->firstOrFail()->payload['sl_price'], 1e-9);
    }

    /**
     * The whole safety property. A trailing stop that retreated would not be one.
     */
    public function test_a_stop_is_never_moved_away_from_the_entry(): void
    {
        $this->settings->update(['copier_trail_distance_r' => 0.5]);

        // The stop already sits at 2658, better than the 2657.50 the trail would ask for.
        $this->trade(sl: 2658.0);
        $this->bars(high: 2660.0);

        $this->assertSame([], (new PositionManager)->manage($this->user));
        $this->assertSame(0, TradeCommand::where('type', 'modify')->count());
    }

    public function test_an_unchanged_trail_is_not_re_queued_every_pass(): void
    {
        $this->settings->update(['copier_trail_distance_r' => 0.5]);

        $this->trade();
        $this->bars(high: 2660.0);

        (new PositionManager)->manage($this->user);
        (new PositionManager)->manage($this->user);

        $this->assertSame(1, TradeCommand::where('type', 'modify')->count());
    }

    // =====================================================================
    // PROFIT LOCK
    // =====================================================================

    public function test_a_share_of_the_position_is_banked_at_the_trigger(): void
    {
        $this->settings->update(['copier_profit_lock_pct' => 50]);

        $this->trade(lots: 0.10);
        $this->bars(high: 2656.0);

        $actions = (new PositionManager)->manage($this->user);

        $this->assertContains('profit_lock', $actions);
        $this->assertEqualsWithDelta(0.05, TradeCommand::where('type', 'close')->firstOrFail()->payload['volume'], 1e-9);
    }

    public function test_profit_is_locked_once_and_not_on_every_pass(): void
    {
        $this->settings->update(['copier_profit_lock_pct' => 50]);

        $this->trade(lots: 0.10);
        $this->bars(high: 2656.0);

        (new PositionManager)->manage($this->user);
        (new PositionManager)->manage($this->user);

        // Otherwise every minute takes another half until the position is gone.
        $this->assertSame(1, TradeCommand::where('type', 'close')->count());
    }

    /**
     * "Lock some profit" must never become a full exit.
     */
    public function test_a_lock_that_would_leave_less_than_the_minimum_is_skipped(): void
    {
        $this->settings->update(['copier_profit_lock_pct' => 50]);

        $this->trade(lots: 0.01);
        $this->bars(high: 2656.0);

        $this->assertNotContains('profit_lock', (new PositionManager)->manage($this->user));
        $this->assertSame(0, TradeCommand::where('type', 'close')->count());
    }

    // =====================================================================
    // WHAT IT LEAVES ALONE
    // =====================================================================

    public function test_the_strategys_own_trades_are_not_touched(): void
    {
        $this->settings->update(['copier_breakeven' => true]);

        // TradeManager owns these, and its ladder means something different.
        $this->trade(origin: 'bot');
        $this->bars(high: 2660.0);

        $this->assertSame([], (new PositionManager)->manage($this->user));
    }

    public function test_nothing_configured_does_nothing(): void
    {
        $this->settings->update(['copier_protect_at_r' => null, 'copier_breakeven' => true]);

        $this->trade();
        $this->bars(high: 2660.0);

        // A deployment predating these settings was not managing copied trades, and
        // upgrading must not silently start.
        $this->assertSame([], (new PositionManager)->manage($this->user));
    }

    public function test_an_offline_terminal_is_not_sent_instructions(): void
    {
        $this->settings->update(['copier_breakeven' => true]);
        BotHeartbeat::where('user_id', $this->user->id)->update(['last_seen_at' => now()->subHour()]);

        $this->trade();
        $this->bars(high: 2660.0);

        $this->assertSame([], (new PositionManager)->manage($this->user));
    }

    public function test_a_trade_with_no_stop_cannot_be_measured_and_is_left_alone(): void
    {
        $this->settings->update(['copier_breakeven' => true]);

        $this->trade(sl: null);
        $this->bars(high: 2660.0);

        // There is no R without a stop, so there is no trigger. A refusal, not a guess.
        $this->assertSame([], (new PositionManager)->manage($this->user));
    }

    // =====================================================================
    // HELPERS
    // =====================================================================

    private function trade(?float $sl = 2645.0, float $lots = 0.05, string $origin = 'ai'): Trade
    {
        return Trade::create([
            'user_id' => $this->user->id,
            'strategy_id' => $this->strategy->id,
            'broker_account_id' => $this->account->id,
            'mt5_ticket' => 910001,
            'symbol' => 'XAUUSD', 'direction' => 'buy',
            'initial_lot_size' => $lots, 'remaining_lot_size' => $lots,
            'entry_price' => 2650.0, 'sl_price' => $sl,
            'status' => 'open', 'origin' => $origin,
            'opened_at' => now()->subMinutes(30),
        ]);
    }

    private function bars(float $high): void
    {
        for ($i = 5; $i >= 0; $i--) {
            Candle::create([
                'user_id' => $this->user->id, 'broker_account_id' => $this->account->id,
                'symbol' => 'XAUUSD', 'timeframe' => 'M5',
                'open_time' => now()->subMinutes(5 * $i),
                'open' => 2650, 'high' => $high, 'low' => 2649, 'close' => $high - 1,
            ]);
        }
    }
}
