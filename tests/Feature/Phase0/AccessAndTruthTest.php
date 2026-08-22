<?php

namespace Tests\Feature\Phase0;

use App\Livewire\Pages\Analytics;
use App\Livewire\Pages\LiveTrades;
use App\Models\BrokerAccount;
use App\Models\Strategy;
use App\Models\Trade;
use App\Models\TradeCommand;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Audit findings F1-F4.
 *
 * Every test here fails against the code as it was. They are grouped because they share a
 * shape rather than a subsystem: each was a silent failure in the flattering direction - an
 * open door that looked shut, metrics that omitted the losses, costs that read as zero, and a
 * position that looked like it was closing for ever.
 */
class AccessAndTruthTest extends TestCase
{
    use RefreshDatabase;

    private function user(array $attributes = []): User
    {
        return User::factory()->create($attributes);
    }

    private function account(User $user): BrokerAccount
    {
        return BrokerAccount::create([
            'user_id' => $user->id,
            'label' => 'Octa Demo',
            'broker_name' => 'Octa',
            'account_number' => '12345678',
            'server' => 'OctaFX-Demo',
            'is_demo' => true,
            'is_active' => true,
        ]);
    }

    private function trade(User $user, array $overrides = []): Trade
    {
        static $ticket = 600000;
        $ticket++;

        return Trade::create(array_merge([
            'user_id' => $user->id,
            'strategy_id' => Strategy::where('user_id', $user->id)->value('id'),
            'broker_account_id' => $this->account($user)->id,
            'mt5_ticket' => $ticket,
            'symbol' => 'XAUUSDm',
            'direction' => 'buy',
            'initial_lot_size' => 0.10,
            'remaining_lot_size' => 0,
            'entry_price' => 2000.00,
            'sl_price' => 1995.00,
            'status' => 'fully_closed',
            'opened_at' => now()->subHours(2),
            'closed_at' => now()->subHour(),
        ], $overrides));
    }

    // =====================================================================
    // F1 - the admin panel
    // =====================================================================

    public function test_an_ordinary_account_cannot_reach_the_admin_panel(): void
    {
        $this->actingAs($this->user())
            ->get('/admin')
            ->assertForbidden();
    }

    public function test_an_admin_can_reach_the_admin_panel(): void
    {
        $this->actingAs($this->user(['is_admin' => true]))
            ->get('/admin')
            ->assertSuccessful();
    }

    /**
     * Admin is a privilege, not a profile field. Mass assignment from any form that happens
     * to accept user input must not be able to set it.
     */
    public function test_admin_cannot_be_granted_by_mass_assignment(): void
    {
        $user = User::create([
            'name' => 'Sneaky',
            'email' => 'sneaky@example.test',
            'password' => 'password',
            'is_admin' => true,
        ]);

        $this->assertFalse((bool) $user->fresh()->is_admin);
    }

    public function test_new_accounts_are_not_admins(): void
    {
        $this->assertFalse((bool) $this->user()->is_admin);
    }

    public function test_the_admin_link_is_hidden_from_ordinary_users(): void
    {
        $this->actingAs($this->user())
            ->get(route('dashboard'))
            ->assertSuccessful()
            ->assertDontSee('Admin Panel');
    }

    public function test_the_admin_link_is_shown_to_admins(): void
    {
        $this->actingAs($this->user(['is_admin' => true]))
            ->get(route('dashboard'))
            ->assertSee('Admin Panel');
    }

    /**
     * The route is not defined at all while registration is off, so `Route::has('register')`
     * is a single source of truth the views can read.
     */
    public function test_registration_is_closed_by_default(): void
    {
        $this->assertFalse(Route::has('register'));
        $this->get('/register')->assertNotFound();
    }

    public function test_the_landing_page_offers_sign_in_when_sign_up_is_closed(): void
    {
        $this->get('/')
            ->assertSuccessful()
            ->assertSee('Sign In')
            ->assertDontSee('Create Free Account');
    }

    public function test_the_grant_command_toggles_admin_access(): void
    {
        $user = $this->user(['email' => 'owner@example.test']);

        $this->artisan('user:admin owner@example.test')->assertSuccessful();
        $this->assertTrue((bool) $user->fresh()->is_admin);

        $this->artisan('user:admin owner@example.test --revoke')->assertSuccessful();
        $this->assertFalse((bool) $user->fresh()->is_admin);
    }

    public function test_the_grant_command_reports_an_unknown_account(): void
    {
        $this->artisan('user:admin nobody@example.test')->assertFailed();
    }

    // =====================================================================
    // F2 - analytics excluded every stop-out
    // =====================================================================

    /**
     * One winner and one stop-out is a 50% win rate. Before this, the stop-out was invisible
     * and the page reported 100%.
     */
    public function test_stopped_out_trades_count_towards_the_win_rate(): void
    {
        $user = $this->user();

        $this->trade($user, ['status' => 'fully_closed', 'net_pnl_money' => 120, 'gross_pnl_money' => 130]);
        $this->trade($user, ['status' => 'stopped_out', 'net_pnl_money' => -95, 'gross_pnl_money' => -90]);

        $metrics = Livewire::actingAs($user)->test(Analytics::class)->viewData('metrics');

        $this->assertSame(2, $metrics['total_trades']);
        $this->assertEqualsWithDelta(50.0, $metrics['win_rate'], 0.01);
    }

    public function test_a_stop_out_is_included_in_net_profit_and_loss(): void
    {
        $user = $this->user();

        $this->trade($user, ['status' => 'fully_closed', 'net_pnl_money' => 120]);
        $this->trade($user, ['status' => 'stopped_out', 'net_pnl_money' => -95]);

        $this->assertEqualsWithDelta(
            25.0,
            (float) Livewire::actingAs($user)->test(Analytics::class)->viewData('metrics')['net_pnl'],
            0.01,
        );
    }

    /**
     * A stop-out is the losing half of the distribution, so it has to reach the profit factor
     * or the ratio is computed with no losses in the denominator.
     */
    public function test_a_stop_out_reaches_the_profit_factor(): void
    {
        $user = $this->user();

        $this->trade($user, ['status' => 'fully_closed', 'gross_pnl_money' => 200]);
        $this->trade($user, ['status' => 'stopped_out', 'gross_pnl_money' => -100]);

        $this->assertEqualsWithDelta(
            2.0,
            (float) Livewire::actingAs($user)->test(Analytics::class)->viewData('metrics')['profit_factor'],
            0.01,
        );
    }

    /**
     * An open position has no settled result, so counting it would report a win or loss that
     * has not happened.
     */
    public function test_open_positions_are_not_counted_as_results(): void
    {
        $user = $this->user();

        $this->trade($user, ['status' => 'fully_closed', 'net_pnl_money' => 50]);
        $this->trade($user, ['status' => 'partially_closed', 'net_pnl_money' => 999, 'closed_at' => null]);
        $this->trade($user, ['status' => 'cancelled', 'net_pnl_money' => 999]);

        $this->assertSame(1, Livewire::actingAs($user)->test(Analytics::class)->viewData('metrics')['total_trades']);
    }

    // =====================================================================
    // F3 - costs read as zero
    // =====================================================================

    public function test_total_costs_are_summable_as_an_attribute(): void
    {
        $user = $this->user();

        $trade = $this->trade($user, [
            'entry_spread_money' => -2.50,
            'commission_money' => -1.40,
            'swap_money' => -0.60,
        ]);

        $this->assertEqualsWithDelta(-4.50, $trade->total_costs_money, 0.001);
        $this->assertEqualsWithDelta(-4.50, Trade::where('id', $trade->id)->get()->sum('total_costs_money'), 0.001);
    }

    public function test_analytics_reports_real_costs_rather_than_zero(): void
    {
        $user = $this->user();

        $this->trade($user, [
            'status' => 'fully_closed',
            'entry_spread_money' => -2.50,
            'commission_money' => -1.40,
            'swap_money' => -0.60,
        ]);

        $this->assertEqualsWithDelta(
            -4.50,
            (float) Livewire::actingAs($user)->test(Analytics::class)->viewData('metrics')['total_costs'],
            0.001,
        );
    }

    // =====================================================================
    // F4 - expired commands
    // =====================================================================

    public function test_a_lapsed_close_gives_the_close_button_back(): void
    {
        $user = $this->user();
        $trade = $this->trade($user, ['status' => 'open', 'remaining_lot_size' => 0.10, 'closed_at' => null]);

        $command = TradeCommand::enqueue(
            user: $user,
            type: 'close',
            payload: ['ticket' => $trade->mt5_ticket, 'volume' => 0.10, 'reason' => 'manual', 'trade_id' => $trade->id],
            idempotencyKey: "close:{$trade->id}:manual",
            expiresInSeconds: 120,
        );

        // Asserted on the state label rather than the word: the page's own explainer copy
        // says "How closing works", so a bare assertDontSee('closing') can never pass.
        $label = 'closing&hellip;';

        Livewire::actingAs($user)->test(LiveTrades::class)->assertSee($label, false);

        $command->update(['expires_at' => now()->subMinute()]);

        // Lapsed: the operator must be able to try again.
        Livewire::actingAs($user)->test(LiveTrades::class)
            ->assertDontSee($label, false)
            ->assertSee('Close');
    }

    public function test_the_sweep_marks_lapsed_commands_expired(): void
    {
        $user = $this->user();

        $live = TradeCommand::enqueue($user, 'close_all', idempotencyKey: 'live', expiresInSeconds: 600);
        $lapsed = TradeCommand::enqueue($user, 'close_all', idempotencyKey: 'lapsed', expiresInSeconds: 600);
        $lapsed->update(['expires_at' => now()->subMinute()]);

        $this->artisan('commands:sweep')->assertSuccessful();

        $this->assertSame('expired', $lapsed->fresh()->status);
        $this->assertSame('pending', $live->fresh()->status);
    }

    /**
     * A command with no expiry is an exit, and an exit that is late is still the exit.
     */
    public function test_commands_without_an_expiry_are_never_swept(): void
    {
        $user = $this->user();
        $forever = TradeCommand::enqueue($user, 'close', idempotencyKey: 'forever');

        $this->artisan('commands:sweep');

        $this->assertSame('pending', $forever->fresh()->status);
    }

    /**
     * An executor that claimed a command and then died leaves it claimed; nothing else would
     * ever move it.
     */
    public function test_a_claimed_but_lapsed_command_is_swept(): void
    {
        $user = $this->user();
        $stuck = TradeCommand::enqueue($user, 'open', idempotencyKey: 'stuck', expiresInSeconds: 60);
        $stuck->update(['status' => 'claimed', 'expires_at' => now()->subMinute()]);

        $this->artisan('commands:sweep');

        $this->assertSame('expired', $stuck->fresh()->status);
    }

    public function test_sweeping_does_not_change_execution_eligibility(): void
    {
        $user = $this->user();
        $lapsed = TradeCommand::enqueue($user, 'open', idempotencyKey: 'lapsed-open', expiresInSeconds: 60);
        $lapsed->update(['expires_at' => now()->subMinute()]);

        // scopeClaimable already refused it; the sweep only makes that visible in the row.
        $this->assertSame(0, TradeCommand::query()->claimable()->count());
        $this->artisan('commands:sweep');
        $this->assertSame(0, TradeCommand::query()->claimable()->count());
    }
}
