<?php

namespace Tests\Feature;

use App\Livewire\Pages\Setup;
use App\Models\BotHeartbeat;
use App\Models\BotSettings;
use App\Models\BotToken;
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

    // =====================================================================
    // THE STEPS ARE DERIVED
    // =====================================================================

    public function test_an_account_with_no_broker_account_starts_at_the_first_step(): void
    {
        $this->account->delete();

        Livewire::actingAs($this->user)->test(Setup::class)
            ->assertViewHas('current', 0)
            ->assertViewHas('ready', false)
            ->assertSee('How a signal becomes a trade on your MT5')
            ->assertSee('No broker account added yet');
    }

    public function test_a_fresh_account_starts_at_the_first_unmet_step(): void
    {
        // A broker account exists from setUp, so the terminal is the first thing not true.
        Livewire::actingAs($this->user)->test(Setup::class)
            ->assertViewHas('current', 1)
            ->assertViewHas('ready', false)
            ->assertSee('No token issued, no terminal has connected');
    }

    public function test_completed_steps_are_marked_from_the_system_not_from_a_flag(): void
    {
        $this->onlineTerminal();

        Livewire::actingAs($this->user)->test(Setup::class)
            // Account and terminal are both satisfied; sources are next.
            ->assertViewHas('current', 2)
            ->assertSee('Signal sources');
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
            ->assertViewHas('current', 2);
    }

    public function test_a_stale_terminal_is_not_counted_as_connected(): void
    {
        $this->channel(enabled: true);
        $this->fund();
        $this->onlineTerminal(lastSeen: now()->subHour());

        Livewire::actingAs($this->user)->test(Setup::class)
            ->assertViewHas('current', 1)
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
            ->set('step', 1)
            ->assertSee('No broker password is stored');
    }

    // =====================================================================
    // EACH STEP LINKS INTO ITS TAB
    // =====================================================================

    public function test_each_step_links_to_the_page_that_completes_it(): void
    {
        $html = Livewire::actingAs($this->user)->test(Setup::class)->html();

        foreach (['broker-accounts', 'terminal', 'signals.channels', 'settings'] as $route) {
            $this->assertStringContainsString('href="'.route($route).'"', $html, "link to {$route}");
        }
    }

    /**
     * The archive is useless without a token to paste into it, so the download is offered
     * only once one exists - otherwise the first thing on the page sends people the wrong way.
     */
    public function test_the_ea_download_is_offered_once_a_token_exists(): void
    {
        Livewire::actingAs($this->user)->test(Setup::class)
            ->assertDontSee('Download EA')
            ->assertDontSee(route('terminal.download'));

        BotToken::generate($this->user, 'VPS', $this->account);

        Livewire::actingAs($this->user)->test(Setup::class)
            ->assertSee('Download EA')
            ->assertSee(route('terminal.download'));
    }

    public function test_the_risk_step_summarises_the_numbers_that_matter(): void
    {
        BotSettings::where('user_id', $this->user->id)->update([
            'risk_percentage' => 1.50, 'max_daily_loss_percentage' => 4.00,
        ]);

        Livewire::actingAs($this->user)->test(Setup::class)
            ->assertSee('1.5% per trade · 4% daily stop · no fund set');

        $this->fund();

        Livewire::actingAs($this->user)->test(Setup::class)
            ->assertSee('1.5% per trade · 4% daily stop · fund $500.00');
    }

    // =====================================================================
    // THE SWITCH IN THE HEADER
    // =====================================================================

    public function test_the_header_shows_whether_auto_trade_is_on(): void
    {
        Livewire::actingAs($this->user)->test(Setup::class)
            ->assertViewHas('autoTrade', false)
            ->assertSee('Auto-trade off');

        BotSettings::where('user_id', $this->user->id)->update(['is_active' => true]);

        Livewire::actingAs($this->user)->test(Setup::class)
            ->assertViewHas('autoTrade', true)
            ->assertSee('Auto-trade on');
    }

    /**
     * The same flag the risk tab's master switch flips, so the two can never disagree.
     */
    public function test_the_header_pill_toggles_the_same_flag_as_the_master_switch(): void
    {
        $component = Livewire::actingAs($this->user)->test(Setup::class)->call('toggleAutoTrade');

        $this->assertTrue((bool) BotSettings::where('user_id', $this->user->id)->value('is_active'));
        $component->assertSee('Auto-trade on');

        $component->call('toggleAutoTrade');

        $this->assertFalse((bool) BotSettings::where('user_id', $this->user->id)->value('is_active'));
        $component->assertSee('Auto-trade off');
    }

    /**
     * A user created outside registration has no settings row until something writes one.
     */
    public function test_the_pill_creates_the_settings_row_when_a_user_has_none(): void
    {
        BotSettings::where('user_id', $this->user->id)->delete();

        Livewire::actingAs($this->user)->test(Setup::class)->call('toggleAutoTrade');

        $this->assertTrue((bool) BotSettings::where('user_id', $this->user->id)->sole()->is_active);
    }

    // =====================================================================
    // HELPERS
    // =====================================================================

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
