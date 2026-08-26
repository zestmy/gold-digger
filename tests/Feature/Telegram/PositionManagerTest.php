<?php

namespace Tests\Feature\Telegram;

use App\Livewire\Pages\Settings;
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
use Livewire\Livewire;
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
        // Pinned rather than inherited. The columns now default to this account's chosen
        // protection - bank half, then trail - so a test of one branch would otherwise
        // silently exercise another, and trailing supersedes break-even.
        $this->settings->update([
            'is_active' => true,
            'copier_protect_at_r' => 1.00,
            'copier_breakeven' => false,
            'copier_breakeven_offset_pips' => null,
            'copier_profit_lock_pct' => null,
            'copier_trail_distance_r' => null,
        ]);

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
            'entry_price' => 2650.0, 'sl_price' => $sl, 'initial_sl_price' => $sl,
            'status' => 'open', 'origin' => $origin,
            'opened_at' => now()->subMinutes(30),
        ]);
    }

    // =====================================================================
    // BREAK-EVEN HAS TO ACTUALLY BREAK EVEN
    // =====================================================================

    /**
     * Closing at the entry books the cost of getting there as a loss.
     *
     * The spread crossed on the way in is already paid and commission is still owed, so a
     * stop on the entry exactly turns every rescued trade into a small loser. The offset is
     * what makes the phrase true.
     */
    public function test_the_break_even_stop_clears_the_cost_of_the_entry(): void
    {
        $this->settings->update([
            'copier_breakeven' => true,
            'copier_breakeven_offset_pips' => 20.0,
        ]);

        $this->trade();
        $this->bars(high: 2656.0);

        $this->assertSame(['break_even'], (new PositionManager)->manage($this->user));

        // 20 pips at 0.10 a pip is 2.00 of price, above the 2650 entry on a buy.
        $this->assertSame(2652.0, (float) TradeCommand::latest('id')->first()->payload['sl_price']);
    }

    public function test_the_offset_runs_the_other_way_on_a_sell(): void
    {
        $this->settings->update([
            'copier_breakeven' => true,
            'copier_breakeven_offset_pips' => 20.0,
        ]);

        // Entry 2650, stop 2655: one R is 5 points, and profit is downward.
        $trade = $this->trade(sl: 2655.0);
        $trade->update(['direction' => 'sell']);
        $this->bars(high: 2651.0);
        // Best 2644, and still in profit at 2645 - so a stop at 2648 sits above the market,
        // which is the right side of it for a sell.
        Candle::where('broker_account_id', $this->account->id)
            ->update(['low' => 2644.0, 'close' => 2645.0]);

        $this->assertSame(['break_even'], (new PositionManager)->manage($this->user));
        $this->assertSame(2648.0, (float) TradeCommand::latest('id')->first()->payload['sl_price']);
    }

    /**
     * Unconfigured, it behaves exactly as it did before the setting existed.
     */
    public function test_without_an_offset_the_stop_still_goes_to_the_entry(): void
    {
        $this->settings->update(['copier_breakeven' => true]);

        $this->trade();
        $this->bars(high: 2656.0);

        (new PositionManager)->manage($this->user);

        $this->assertSame(2650.0, (float) TradeCommand::latest('id')->first()->payload['sl_price']);
    }

    /**
     * An offset wider than the move would put the stop through the market.
     *
     * The broker refuses a stop on the wrong side of price, or fills it as an immediate
     * exit - so a padding the trade has not earned is dropped rather than sent, and the
     * entry still gets protected.
     */
    public function test_an_offset_the_trade_has_not_earned_is_dropped(): void
    {
        $this->settings->update([
            'copier_breakeven' => true,
            // 100 pips is 10.00 of price. The trade's best is only 6.00 above the entry.
            'copier_breakeven_offset_pips' => 100.0,
        ]);

        $this->trade();
        $this->bars(high: 2656.0);

        $this->assertSame(['break_even'], (new PositionManager)->manage($this->user));
        $this->assertSame(2650.0, (float) TradeCommand::latest('id')->first()->payload['sl_price']);
    }

    /**
     * A position that ran and came back must not be padded past where price is now.
     *
     * The best price since entry says the offset was earned; the last close says the stop
     * would sit above the market. A stop above the market on a buy is not protection, it is
     * an exit, so the padding is dropped and the entry is protected instead.
     */
    public function test_the_offset_is_dropped_when_price_has_retraced_behind_it(): void
    {
        $this->settings->update([
            'copier_breakeven' => true,
            // 40 pips is 4.00. Earned against a best of 2660, not against a close of 2651.
            'copier_breakeven_offset_pips' => 40.0,
        ]);

        $this->trade();
        $this->bars(high: 2660.0);
        Candle::where('broker_account_id', $this->account->id)
            ->orderByDesc('open_time')->limit(1)->update(['close' => 2651.0]);

        $this->assertSame(['break_even'], (new PositionManager)->manage($this->user));
        $this->assertSame(2650.0, (float) TradeCommand::latest('id')->first()->payload['sl_price']);
    }

    /**
     * The offset reaches the setting it is configured with.
     */
    public function test_the_offset_is_saved_from_the_settings_page(): void
    {
        $this->actingAs($this->user);

        Livewire::test(Settings::class)
            ->set('copier_protect_at_r', '1.0')
            ->set('copier_breakeven', true)
            ->set('copier_breakeven_offset_pips', '20')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertEquals(20.0, $this->settings->fresh()->copier_breakeven_offset_pips);
    }

    /**
     * "Not configured" and "configured to nothing" are the same behaviour but not the same
     * state, and the box must not turn one into a zero on every save.
     */
    public function test_an_empty_offset_box_stays_unconfigured(): void
    {
        $this->actingAs($this->user);

        Livewire::test(Settings::class)
            ->set('copier_breakeven_offset_pips', '')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertNull($this->settings->fresh()->copier_breakeven_offset_pips);
    }

    /**
     * Trailing supersedes break-even, so the offset has nothing to do with it.
     */
    public function test_the_offset_does_not_touch_a_trailing_stop(): void
    {
        $this->settings->update([
            'copier_breakeven' => true,
            'copier_breakeven_offset_pips' => 20.0,
            'copier_trail_distance_r' => 1.0,
        ]);

        $this->trade();
        $this->bars(high: 2660.0);

        $this->assertSame(['trail'], (new PositionManager)->manage($this->user));
        $this->assertSame(2655.0, (float) TradeCommand::latest('id')->first()->payload['sl_price']);
    }

    // =====================================================================
    // R IS THE RISK THE TRADE OPENED WITH
    // =====================================================================

    /**
     * The trail must keep moving after the first move.
     *
     * `sl_price` is live - `PositionReconciler` writes the terminal's actual stop back onto
     * the row - so computing R from it means measuring against this class's own last
     * decision. The first trail landed on the entry, R became zero, and the position
     * dropped out of management for the rest of its life while the price ran on.
     */
    public function test_the_trail_keeps_moving_after_it_has_already_moved_the_stop(): void
    {
        $this->settings->update(['copier_trail_distance_r' => 1.0]);

        // Entry 2650, opening stop 2645: one R is 5 points.
        $trade = $this->trade();
        $this->bars(high: 2655.0);

        $this->assertSame(['trail'], (new PositionManager)->manage($this->user));
        $this->assertSame(2650.0, (float) TradeCommand::latest('id')->first()->payload['sl_price']);

        // The terminal reports the move back, exactly as reconciliation would.
        $trade->update(['sl_price' => 2650.0]);
        $this->bars(high: 2660.0);

        $actions = (new PositionManager)->manage($this->user);

        $this->assertSame(['trail'], $actions, 'A break-even stop must not end management.');
        // Still one R behind the high, measured on the opening risk of 5 - not on the
        // zero distance between the entry and the stop now sitting on it.
        $this->assertSame(2655.0, (float) TradeCommand::latest('id')->first()->payload['sl_price']);
    }

    /**
     * And the distance must stay the multiple that was configured.
     */
    public function test_the_trail_distance_does_not_drift_as_the_stop_advances(): void
    {
        $this->settings->update(['copier_trail_distance_r' => 0.5]);

        // Entry 2650, opening stop 2645: one R is 5 points, so half an R is 2.5.
        $trade = $this->trade();
        $this->bars(high: 2660.0);

        (new PositionManager)->manage($this->user);
        $this->assertSame(2657.5, (float) TradeCommand::latest('id')->first()->payload['sl_price']);

        // The stop now sits 7.5 above the entry. Measured against it, R reads 7.5 and
        // half of that is 3.75 - so the next trail would sit at 2666.25, a distance
        // nothing configured.
        $trade->update(['sl_price' => 2657.5]);
        $this->bars(high: 2670.0);

        (new PositionManager)->manage($this->user);
        $this->assertSame(2667.5, (float) TradeCommand::latest('id')->first()->payload['sl_price']);
    }

    /**
     * A position that predates the column is managed as it always was, not skipped.
     */
    public function test_a_trade_without_a_recorded_opening_stop_falls_back_to_the_live_one(): void
    {
        $this->settings->update(['copier_breakeven' => true]);

        $trade = $this->trade();
        $trade->update(['initial_sl_price' => null]);
        $this->bars(high: 2656.0);

        $this->assertNotSame([], (new PositionManager)->manage($this->user));
    }

    private function bars(float $high): void
    {
        // Reseeded rather than appended, so a test can raise the high a second time
        // without colliding with the bar it already wrote for that minute.
        Candle::where('broker_account_id', $this->account->id)->delete();

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
