<?php

namespace Tests\Feature;

use App\Livewire\Pages\Setup;
use App\Models\BotHeartbeat;
use App\Models\BotSettings;
use App\Models\BrokerAccount;
use App\Models\TelegramChannel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The four things that must be true before a copied signal can become a position.
 *
 * The property worth holding: each step's state is asked, not remembered. A wizard that
 * stores how far you got is lying the moment a token is revoked or a terminal stops
 * beating, and going back has to be something the page does by itself.
 */
class SetupWizardTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private BrokerAccount $account;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->account = BrokerAccount::create([
            'user_id' => $this->user->id, 'label' => 'Demo', 'broker_name' => 'Elev8',
            'account_number' => '1', 'server' => 'Elev8-Demo2', 'is_demo' => true, 'is_active' => true,
        ]);
    }

    public function test_a_fresh_account_starts_at_the_first_unmet_step(): void
    {
        Livewire::actingAs($this->user)->test(Setup::class)
            ->assertViewHas('current', 0)
            ->assertViewHas('ready', false)
            ->assertSee('Signal source');
    }

    public function test_completed_steps_are_marked_from_the_system_not_from_a_flag(): void
    {
        $this->channel(enabled: true);

        Livewire::actingAs($this->user)->test(Setup::class)
            // Source and channels are both satisfied by one enabled channel existing.
            ->assertViewHas('current', 2)
            ->assertSee('Terminal');
    }

    /**
     * The reason progress is derived: things stop being true.
     */
    public function test_switching_a_channel_off_moves_the_wizard_back(): void
    {
        $channel = $this->channel(enabled: true);
        $this->onlineTerminal();
        $this->fund();

        Livewire::actingAs($this->user)->test(Setup::class)->assertViewHas('ready', true);

        $channel->update(['is_enabled' => false]);

        Livewire::actingAs($this->user)->test(Setup::class)
            ->assertViewHas('ready', false)
            ->assertViewHas('current', 1);
    }

    public function test_a_stale_terminal_is_not_counted_as_connected(): void
    {
        $this->channel(enabled: true);
        $this->fund();
        $this->onlineTerminal(lastSeen: now()->subHour());

        Livewire::actingAs($this->user)->test(Setup::class)
            ->assertViewHas('current', 2)
            ->assertViewHas('ready', false);
    }

    public function test_everything_connected_says_so(): void
    {
        $this->channel(enabled: true);
        $this->onlineTerminal();
        $this->fund();

        Livewire::actingAs($this->user)->test(Setup::class)
            ->assertViewHas('ready', true)
            ->assertSee('Everything is connected');
    }

    /**
     * The page is instructions, not a comparison. The security property is worth stating
     * because it tells somebody what they do and do not have to hand over.
     */
    public function test_the_terminal_step_says_no_broker_password_is_stored(): void
    {
        Livewire::actingAs($this->user)->test(Setup::class)
            ->set('step', 2)
            ->assertSee('No broker password is stored');
    }

    private function channel(bool $enabled): TelegramChannel
    {
        return TelegramChannel::create([
            'user_id' => $this->user->id, 'source' => TelegramChannel::SOURCE_ACCOUNT,
            'chat_id' => '5001', 'title' => 'FTC 2026', 'is_enabled' => $enabled,
        ]);
    }

    private function onlineTerminal(?Carbon $lastSeen = null): void
    {
        BotHeartbeat::updateOrCreate(
            ['user_id' => $this->user->id, 'source' => 'mql5_ea'],
            [
                'broker_account_id' => $this->account->id,
                'algo_trading_enabled' => true, 'broker_connected' => true,
                'resolved_symbol' => 'XAUUSD', 'last_seen_at' => $lastSeen ?? now(),
            ],
        );
    }

    private function fund(): void
    {
        BotSettings::where('user_id', $this->user->id)->update([
            'ai_trading_enabled' => true,
            'ai_capital_cap' => 500.00,
            'ai_risk_percentage' => 5.00,
        ]);
    }
}
