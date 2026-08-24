<?php

namespace Tests\Feature\Dashboard;

use App\Livewire\Dashboard\AiAnalysisCard;
use App\Livewire\Dashboard\NewsCard;
use App\Livewire\Dashboard\SessionCard;
use App\Livewire\Dashboard\TrendCard;
use App\Models\BotSettings;
use App\Models\BrokerAccount;
use App\Models\Candle;
use App\Models\EconomicEvent;
use App\Models\Strategy;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The market-context cards: trend, session, news, and the written analysis.
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

    public function test_the_trend_card_warms_up_rather_than_inventing_a_direction(): void
    {
        // No bars at all. The card must not show a trend it cannot compute.
        Livewire::test(TrendCard::class)
            ->assertSet('hasStrategy', true)
            ->assertSee('WARMING UP')
            ->assertDontSee('BULLISH')
            ->assertDontSee('BEARISH');
    }

    public function test_the_trend_card_reports_a_direction_once_the_series_is_warm(): void
    {
        $this->seedBars('M5', 200, 2000.0, 0.5);
        $this->seedBars('H1', 200, 2000.0, 0.5);

        Livewire::test(TrendCard::class)
            ->assertDontSee('WARMING UP')
            ->assertSee('BULLISH');
    }

    /**
     * The card and the strategy must never disagree about which way the trend points.
     */
    public function test_the_trend_card_uses_the_same_definition_the_strategy_trades(): void
    {
        $this->seedBars('M5', 200, 2000.0, 0.5);
        $this->seedBars('H1', 200, 2000.0, 0.5);

        $context = Livewire::test(TrendCard::class)->get('context');

        $evaluator = app(\App\Services\Strategy\StrategyEvaluator::class);
        $trendBars = Candle::recentSeries($this->account->id, 'XAUUSD', 'H1', 300);

        $this->assertSame(
            $evaluator->trendDirection($trendBars, $this->strategy->ema_fast, $this->strategy->ema_slow),
            $context['trend'],
            'The trend card must read the evaluator, not compute its own direction.',
        );
    }

    public function test_the_session_card_separates_open_from_allowed(): void
    {
        // 09:00 UTC on a Monday: London is open, but this account trades the overlap only.
        $this->travelTo(Carbon::parse('2026-08-24 09:00:00', 'UTC'));
        $this->settings->update(['allowed_sessions' => ['overlap']]);

        Livewire::test(SessionCard::class)
            ->assertSet('tradingWindowOpen', false)
            ->assertSee('CLOSED')
            // London is genuinely open even though this account will not trade it.
            ->assertSee('London');
    }

    public function test_the_session_card_counts_down_to_the_next_window(): void
    {
        $this->travelTo(Carbon::parse('2026-08-24 05:00:00', 'UTC'));
        $this->settings->update(['allowed_sessions' => ['london']]);

        Livewire::test(SessionCard::class)
            ->assertSet('tradingWindowOpen', false)
            ->assertSet('nextOpenAt', '07:00 UTC');
    }

    public function test_an_unrestricted_account_is_never_out_of_session(): void
    {
        // allowed_sessions is nullable and TradingSession reads empty as "always allowed".
        $this->travelTo(Carbon::parse('2026-08-24 03:00:00', 'UTC'));
        $this->settings->update(['allowed_sessions' => []]);

        Livewire::test(SessionCard::class)
            ->assertSet('unrestricted', true)
            ->assertSet('tradingWindowOpen', true);
    }

    public function test_the_news_card_reports_no_calendar_rather_than_all_clear(): void
    {
        // The filter is on and there is no calendar. Showing CLEAR would be the worst
        // possible answer: it is the one state that looks like protection and is not.
        $this->settings->update(['news_filter_enabled' => true]);

        Livewire::test(NewsCard::class)
            ->assertSet('stale', true)
            ->assertSee('NO CALENDAR')
            ->assertDontSee('CLEAR');
    }

    public function test_the_news_card_shows_the_next_release(): void
    {
        $this->travelTo(Carbon::parse('2026-08-24 09:00:00', 'UTC'));
        $this->settings->update(['news_filter_enabled' => true]);

        EconomicEvent::create([
            'external_id' => 'evt-1',
            'title' => 'Non-Farm Employment Change',
            'currency' => 'USD',
            'impact' => 'high',
            'scheduled_at' => Carbon::parse('2026-08-24 12:30:00', 'UTC'),
            'fetched_at' => now(),
        ]);

        Livewire::test(NewsCard::class)
            ->assertSet('stale', false)
            ->assertSet('inBlackout', false)
            ->assertSee('CLEAR')
            ->assertSee('Non-Farm Employment Change');
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

    public function test_the_dashboard_renders_every_card_together(): void
    {
        $this->seedBars('M5', 200, 2000.0, 0.5);
        $this->seedBars('H1', 200, 2000.0, 0.5);

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Trend')
            ->assertSee('Trading Session')
            ->assertSee('Economic Calendar')
            ->assertSee('Analysis');
    }
}
