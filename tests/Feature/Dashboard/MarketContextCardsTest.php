<?php

namespace Tests\Feature\Dashboard;

use App\Livewire\Dashboard\AiAnalysisCard;
use App\Models\BotSettings;
use App\Models\BrokerAccount;
use App\Models\Candle;
use App\Models\Strategy;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The written analysis card, and what Home no longer carries.
 *
 * The trend, session and news cards this file used to cover left the dashboard when Home
 * became a glance: the trend and session readings are factors on the signal card, and the
 * calendar is one line on the Execution card. The analysis card moved to the Signals page.
 */
class MarketContextCardsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private BrokerAccount $account;

    private Strategy $strategy;

    private BotSettings $settings;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

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

        $this->strategy = Strategy::where('user_id', $this->user->id)->firstOrFail();
        $this->settings = BotSettings::where('user_id', $this->user->id)->firstOrFail();

        $this->actingAs($this->user);
    }

    /**
     * A rising series, enough bars for the evaluator's warm-up rule.
     */
    private function seedBars(string $timeframe, int $count, float $start, float $step): void
    {
        $minutes = $timeframe === 'H1' ? 60 : 5;
        $price = $start;

        for ($i = $count; $i > 0; $i--) {
            Candle::create([
                'user_id' => $this->user->id,
                'broker_account_id' => $this->account->id,
                'symbol' => 'XAUUSD',
                'timeframe' => $timeframe,
                'open_time' => now()->subMinutes($minutes * $i),
                'open' => $price,
                'high' => $price + 1,
                'low' => $price - 1,
                'close' => $price,
            ]);
            $price += $step;
        }
    }

    public function test_the_analysis_card_is_off_without_a_key_and_says_so(): void
    {
        config(['ai.key' => null]);

        Livewire::test(AiAnalysisCard::class)
            ->assertSet('configured', false)
            ->assertSee('OPENROUTER_API_KEY');
    }

    /**
     * The card must never bill a page load.
     */
    public function test_the_analysis_card_does_not_generate_on_mount(): void
    {
        config(['ai.key' => 'sk-ant-test']);

        Livewire::test(AiAnalysisCard::class)
            ->assertSet('configured', true)
            ->assertSet('headline', null)
            ->assertSee('No analysis yet');
    }

    /**
     * Home is a glance now; the context cards left it for the destinations that own them.
     * What stays on Home of the calendar is one line on the Execution card.
     */
    public function test_the_dashboard_renders_without_the_context_cards(): void
    {
        $this->seedBars('M5', 200, 2000.0, 0.5);
        $this->seedBars('H1', 200, 2000.0, 0.5);

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Execution')
            ->assertSee('No calendar')
            ->assertDontSee('Trading Session')
            ->assertDontSee('Economic Calendar');
    }
}
