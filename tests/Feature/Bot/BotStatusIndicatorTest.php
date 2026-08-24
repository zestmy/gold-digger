<?php

namespace Tests\Feature\Bot;

use App\Livewire\BotStatusIndicator;
use App\Livewire\Dashboard\BotStatusCard;
use App\Models\BotHeartbeat;
use App\Models\BrokerAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The sidebar indicator, which used to be hardcoded markup reading "Bot Offline".
 *
 * It said Offline on every page, including while the dashboard card two columns away
 * said ONLINE from the same heartbeat. These pin down that it reads real state, and that
 * it cannot disagree with the card again.
 */
class BotStatusIndicatorTest extends TestCase
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

    private function heartbeat(array $overrides = []): BotHeartbeat
    {
        return BotHeartbeat::create($overrides + [
            'user_id' => $this->user->id,
            'broker_account_id' => $this->account->id,
            'source' => 'mql5_ea',
            'algo_trading_enabled' => true,
            'broker_connected' => true,
            'resolved_symbol' => 'XAUUSD',
            'open_positions' => 0,
            'last_seen_at' => now(),
        ]);
    }

    public function test_it_reports_online_from_a_fresh_heartbeat(): void
    {
        $this->heartbeat();

        Livewire::test(BotStatusIndicator::class)
            ->assertSet('status', BotHeartbeat::STATUS_ONLINE)
            ->assertSee('Bot Online');
    }

    public function test_it_reports_blocked_rather_than_offline_when_algo_trading_is_off(): void
    {
        // The terminal is perfectly healthy and every order would still be refused with
        // 10027. Calling that OFFLINE sends someone to restart a terminal that is fine.
        $this->heartbeat(['algo_trading_enabled' => false]);

        Livewire::test(BotStatusIndicator::class)
            ->assertSet('status', BotHeartbeat::STATUS_BLOCKED)
            ->assertSee('Bot Blocked')
            ->assertDontSee('Bot Online');
    }

    public function test_it_reports_offline_once_the_heartbeat_goes_stale(): void
    {
        $this->heartbeat(['last_seen_at' => now()->subSeconds(BotHeartbeat::STALE_AFTER_SECONDS + 5)]);

        Livewire::test(BotStatusIndicator::class)
            ->assertSet('status', BotHeartbeat::STATUS_OFFLINE)
            ->assertSet('hasEverReported', true)
            ->assertSee('Stopped reporting');
    }

    public function test_it_separates_never_started_from_went_quiet(): void
    {
        Livewire::test(BotStatusIndicator::class)
            ->assertSet('status', BotHeartbeat::STATUS_OFFLINE)
            ->assertSet('hasEverReported', false)
            ->assertSee('No executor has checked in');
    }

    /**
     * The actual bug: two surfaces, one heartbeat, two answers.
     */
    public function test_the_sidebar_and_the_dashboard_card_never_disagree(): void
    {
        foreach ([
            ['algo_trading_enabled' => true,  'broker_connected' => true,  'last_seen_at' => now()],
            ['algo_trading_enabled' => false, 'broker_connected' => true,  'last_seen_at' => now()],
            ['algo_trading_enabled' => true,  'broker_connected' => false, 'last_seen_at' => now()],
            ['algo_trading_enabled' => true,  'broker_connected' => true,  'last_seen_at' => now()->subMinutes(5)],
        ] as $case) {
            BotHeartbeat::query()->delete();
            $this->heartbeat($case);

            $indicator = Livewire::test(BotStatusIndicator::class)->get('status');
            $card = Livewire::test(BotStatusCard::class);

            // The card's vocabulary is (isOnline, blockedReason); the indicator's is a
            // single word. They must describe the same terminal the same way.
            $cardStatus = match (true) {
                ! $card->get('isOnline') => BotHeartbeat::STATUS_OFFLINE,
                $card->get('blockedReason') !== null => BotHeartbeat::STATUS_BLOCKED,
                default => BotHeartbeat::STATUS_ONLINE,
            };

            $this->assertSame(
                $cardStatus,
                $indicator,
                'Sidebar and dashboard card reported different states for '.json_encode($case),
            );
        }
    }

    public function test_an_empty_trades_list_does_not_claim_the_bot_is_offline(): void
    {
        // The Recent Trades empty state used to assert "Bot is offline." It has no
        // heartbeat to read, and a bot that simply has not taken a setup is not offline.
        $this->heartbeat();

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('Bot is offline.');
    }
}
