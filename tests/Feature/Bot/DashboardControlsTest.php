<?php

namespace Tests\Feature\Bot;

use App\Livewire\Dashboard\BotStatusCard;
use App\Livewire\Dashboard\QuickActionsCard;
use App\Models\BotHeartbeat;
use App\Models\BrokerAccount;
use App\Models\TradeCommand;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The dashboard controls used to be stubs that flashed "available in Phase 3".
 * These pin down that they now actually queue work and read real state.
 */
class DashboardControlsTest extends TestCase
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
            'label' => 'Octa Demo',
            'broker_name' => 'Octa',
            'account_number' => '12345678',
            'server' => 'OctaFX-Demo',
            'is_demo' => true,
            'is_active' => true,
        ]);

        $this->actingAs($this->user);
    }

    public function test_starting_the_bot_queues_a_command_and_flips_the_kill_switch(): void
    {
        Livewire::test(QuickActionsCard::class)->call('startBot');

        $this->assertTrue($this->user->botSettings->fresh()->is_active);

        $command = TradeCommand::first();
        $this->assertSame('start', $command->type);
        $this->assertSame($this->account->id, $command->broker_account_id);
    }

    public function test_stopping_the_bot_flips_the_kill_switch_even_before_the_command_is_claimed(): void
    {
        $this->user->botSettings->update(['is_active' => true]);

        Livewire::test(QuickActionsCard::class)->call('stopBot');

        // The flag is the real kill switch; the queued command is only the courtesy
        // notification. Stopping must not depend on delivery.
        $this->assertFalse($this->user->botSettings->fresh()->is_active);
        $this->assertSame('stop', TradeCommand::first()->type);
    }

    public function test_double_clicking_close_all_queues_only_one_command(): void
    {
        $card = Livewire::test(QuickActionsCard::class);
        $card->call('closeAllPositions');
        $card->call('closeAllPositions');

        $this->assertSame(1, TradeCommand::where('type', 'close_all')->count());
    }

    public function test_close_all_expires_so_a_stale_flatten_is_never_executed(): void
    {
        Livewire::test(QuickActionsCard::class)->call('closeAllPositions');

        $this->assertNotNull(TradeCommand::first()->expires_at);
    }

    public function test_status_card_reports_offline_when_nothing_has_checked_in(): void
    {
        Livewire::test(BotStatusCard::class)
            ->assertSet('isOnline', false)
            ->assertSet('blockedReason', 'No executor has ever checked in. Is the EA attached to a chart?');
    }

    public function test_status_card_reports_online_from_a_fresh_heartbeat(): void
    {
        BotHeartbeat::create([
            'user_id' => $this->user->id,
            'broker_account_id' => $this->account->id,
            'source' => 'mql5_ea',
            'algo_trading_enabled' => true,
            'broker_connected' => true,
            'resolved_symbol' => 'XAUUSDm',
            'open_positions' => 2,
            'last_seen_at' => now(),
        ]);

        Livewire::test(BotStatusCard::class)
            ->assertSet('isOnline', true)
            ->assertSet('blockedReason', null)
            ->assertSet('resolvedSymbol', 'XAUUSDm')
            ->assertSet('activeBroker', 'Octa Demo')
            ->assertSet('openPositions', 2);
    }

    public function test_status_card_distinguishes_blocked_from_offline(): void
    {
        // The failure that otherwise looks like "the bot just never trades".
        BotHeartbeat::create([
            'user_id' => $this->user->id,
            'source' => 'mql5_ea',
            'algo_trading_enabled' => false,
            'broker_connected' => true,
            'last_seen_at' => now(),
        ]);

        Livewire::test(BotStatusCard::class)
            ->assertSet('isOnline', true)
            ->assertSee('Algo Trading is disabled');
    }

    public function test_status_card_reports_offline_once_the_heartbeat_goes_stale(): void
    {
        BotHeartbeat::create([
            'user_id' => $this->user->id,
            'source' => 'mql5_ea',
            'algo_trading_enabled' => true,
            'broker_connected' => true,
            'last_seen_at' => now()->subSeconds(BotHeartbeat::STALE_AFTER_SECONDS + 5),
        ]);

        Livewire::test(BotStatusCard::class)
            ->assertSet('isOnline', false)
            ->assertSee('No heartbeat');
    }
}
