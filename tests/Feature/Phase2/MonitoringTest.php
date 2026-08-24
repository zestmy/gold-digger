<?php

namespace Tests\Feature\Phase2;

use App\Livewire\Dashboard\DailyChartCard;
use App\Models\Alert;
use App\Models\BotHeartbeat;
use App\Models\BotSettings;
use App\Models\BrokerAccount;
use App\Models\Candle;
use App\Models\Strategy;
use App\Models\Trade;
use App\Models\TradePartial;
use App\Models\User;
use App\Services\Monitoring\HealthMonitor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Health monitoring, alerting and the equity curve.
 *
 * The lifecycle is what these mostly pin down. An alert that fires every minute is noise, one
 * that never clears trains you to ignore the channel, and one that fires for a bot you
 * deliberately switched off is both. Each is a way for monitoring to become worse than none.
 */
class MonitoringTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private BrokerAccount $account;

    private BotSettings $settings;

    private Strategy $strategy;

    private const SYMBOL = 'XAUUSDm';

    protected function setUp(): void
    {
        parent::setUp();

        // No default fake: Http::fake() appends stub callbacks and the first match wins, so
        // a stub registered here could never be overridden by a test that needs a failing
        // channel. preventStrayRequests stays, which turns any unfaked send into a failure
        // rather than a real request.
        Http::preventStrayRequests();

        config()->set('alerts.telegram.token', 'test-token');
        config()->set('alerts.telegram.chat_id', '4242');

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

        $this->settings = BotSettings::where('user_id', $this->user->id)->firstOrFail();
        $this->settings->update(['is_active' => true, 'max_daily_loss_percentage' => 3.00]);

        $this->strategy = Strategy::where('user_id', $this->user->id)->firstOrFail();
        $this->strategy->update(['is_active' => true, 'timeframe_entry' => 'M5']);
    }

    private function heartbeat(array $overrides = []): BotHeartbeat
    {
        return BotHeartbeat::updateOrCreate(
            ['user_id' => $this->user->id, 'source' => 'mql5_ea'],
            array_merge([
                'broker_account_id' => $this->account->id,
                'algo_trading_enabled' => true,
                'broker_connected' => true,
                'resolved_symbol' => self::SYMBOL,
                'pip_size' => 0.10,
                'pip_value_per_lot' => 10.0,
                'balance' => 10000.00,
                'last_seen_at' => now(),
            ], $overrides),
        );
    }

    private function freshBar(): void
    {
        Candle::create([
            'user_id' => $this->user->id,
            'broker_account_id' => $this->account->id,
            'symbol' => self::SYMBOL,
            'timeframe' => 'M5',
            'open_time' => now()->subMinute(),
            'open' => 2000, 'high' => 2001, 'low' => 1999, 'close' => 2000,
        ]);
    }

    private function openTrade(): Trade
    {
        return Trade::create([
            'user_id' => $this->user->id,
            'strategy_id' => $this->strategy->id,
            'broker_account_id' => $this->account->id,
            'mt5_ticket' => 990001,
            'symbol' => self::SYMBOL,
            'direction' => 'buy',
            'initial_lot_size' => 0.10,
            'remaining_lot_size' => 0.10,
            'entry_price' => 2000,
            'sl_price' => 1995,
            'status' => 'open',
            'opened_at' => now()->subHour(),
        ]);
    }

    /**
     * A channel that accepts everything. Called by the tests that expect delivery.
     */
    private function channelAccepts(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
    }

    private function keys(): array
    {
        return Alert::where('user_id', $this->user->id)->firing()->pluck('key')->sort()->values()->all();
    }

    // =====================================================================
    // WHEN SILENCE IS CORRECT
    // =====================================================================

    /**
     * The most important negative case. A bot switched off on purpose is not a fault, and an
     * alert that fires permanently on every idle account is worse than no alerting at all.
     */
    public function test_a_deliberately_stopped_bot_with_nothing_open_raises_nothing(): void
    {
        $this->settings->update(['is_active' => false]);
        $this->heartbeat(['last_seen_at' => now()->subDay()]);

        app(HealthMonitor::class)->sweep();

        $this->assertSame([], $this->keys());
    }

    /**
     * The exception, and the reason the check is not simply "is it switched on": a dead
     * executor still holding positions is precisely the case an owner forgets about.
     */
    public function test_a_stopped_bot_still_holding_positions_does_raise(): void
    {
        $this->settings->update(['is_active' => false]);
        $this->openTrade();
        $this->heartbeat(['last_seen_at' => now()->subDay()]);

        app(HealthMonitor::class)->sweep();

        $this->assertContains('executor_offline', $this->keys());
        $this->assertSame('critical', Alert::where('key', 'executor_offline')->firstOrFail()->level);
    }

    public function test_a_healthy_bot_raises_nothing(): void
    {
        $this->heartbeat();
        $this->freshBar();

        app(HealthMonitor::class)->sweep();

        $this->assertSame([], $this->keys());
    }

    // =====================================================================
    // CONDITIONS
    // =====================================================================

    public function test_a_silent_executor_raises_an_alert(): void
    {
        $this->heartbeat(['last_seen_at' => now()->subMinutes(10)]);

        app(HealthMonitor::class)->sweep();

        $this->assertContains('executor_offline', $this->keys());
    }

    /**
     * Offline with nothing at stake is worth telling somebody about, but it is not the same
     * as offline while holding positions.
     */
    public function test_being_offline_with_nothing_open_is_only_a_warning(): void
    {
        $this->heartbeat(['last_seen_at' => now()->subMinutes(10)]);

        app(HealthMonitor::class)->sweep();

        $this->assertSame('warning', Alert::where('key', 'executor_offline')->firstOrFail()->level);
    }

    /**
     * The failure the whole status card exists for: everything looks healthy and every order
     * would be refused.
     */
    public function test_algo_trading_switched_off_raises_an_alert(): void
    {
        $this->heartbeat(['algo_trading_enabled' => false]);
        $this->freshBar();

        app(HealthMonitor::class)->sweep();

        $this->assertContains('algo_trading_disabled', $this->keys());
    }

    public function test_a_lost_broker_connection_raises_an_alert(): void
    {
        $this->heartbeat(['broker_connected' => false]);
        $this->freshBar();

        app(HealthMonitor::class)->sweep();

        $this->assertContains('broker_disconnected', $this->keys());
    }

    /**
     * A heartbeating executor whose candle push is failing looks, from the dashboard, exactly
     * like a strategy that has seen no setups.
     */
    public function test_a_stalled_price_feed_raises_an_alert(): void
    {
        // Frozen inside the New York session. The check is now session-aware, so a test
        // that runs at whatever the wall clock happens to say would pass all afternoon
        // and fail every evening.
        $this->travelTo(Carbon::parse('2026-08-24 14:00:00', 'UTC'));

        $this->heartbeat();
        $this->staleCandle();

        app(HealthMonitor::class)->sweep();

        $this->assertContains('feed_stalled:M5', $this->keys());
    }

    /**
     * The daily rollover must not page anyone.
     *
     * Brokers close gold for an hour a day - Elev8 at 21:00 UTC - and the last bar before
     * a break never closes, so the push stops legitimately. Alerting on it would fire a
     * warning every night until the channel stopped being read.
     */
    public function test_a_stalled_feed_outside_the_allowed_sessions_is_not_an_alert(): void
    {
        // 21:30 UTC on a Monday: New York (12-21) has closed, and this account trades
        // london/newyork/overlap only.
        $this->travelTo(Carbon::parse('2026-08-24 21:30:00', 'UTC'));

        $this->heartbeat();
        $this->staleCandle();

        app(HealthMonitor::class)->sweep();

        $this->assertNotContains('feed_stalled:M5', $this->keys());
    }

    public function test_a_stalled_feed_at_the_weekend_is_not_an_alert(): void
    {
        // Saturday. No session configuration makes a missing weekend bar interesting, so
        // this holds even for an account with no session restriction at all.
        $this->travelTo(Carbon::parse('2026-08-29 14:00:00', 'UTC'));
        $this->settings->update(['allowed_sessions' => []]);

        $this->heartbeat();
        $this->staleCandle();

        app(HealthMonitor::class)->sweep();

        $this->assertNotContains('feed_stalled:M5', $this->keys());
    }

    private function staleCandle(): void
    {
        Candle::create([
            'user_id' => $this->user->id,
            'broker_account_id' => $this->account->id,
            'symbol' => self::SYMBOL,
            'timeframe' => 'M5',
            'open_time' => now()->subHour(),
            'open' => 2000, 'high' => 2001, 'low' => 1999, 'close' => 2000,
        ]);
    }

    /**
     * One bar late is a slow poll, not an outage. The threshold is three bars.
     */
    public function test_a_feed_one_bar_behind_is_not_stalled(): void
    {
        $this->heartbeat();

        Candle::create([
            'user_id' => $this->user->id,
            'broker_account_id' => $this->account->id,
            'symbol' => self::SYMBOL,
            'timeframe' => 'M5',
            'open_time' => now()->subMinutes(6),
            'open' => 2000, 'high' => 2001, 'low' => 1999, 'close' => 2000,
        ]);

        app(HealthMonitor::class)->sweep();

        $this->assertNotContains('feed_stalled:M5', $this->keys());
    }

    /**
     * An executor that has never pushed is not a stalled feed - it may simply not have sent
     * its first window yet, and executor_offline covers it if it never does.
     */
    public function test_a_feed_that_never_started_is_not_reported_as_stalled(): void
    {
        $this->heartbeat();

        app(HealthMonitor::class)->sweep();

        $this->assertNotContains('feed_stalled:M5', $this->keys());
    }

    public function test_the_daily_loss_limit_raises_an_alert(): void
    {
        $this->heartbeat();
        $this->freshBar();

        $trade = $this->openTrade();
        $trade->update(['status' => 'fully_closed', 'closed_at' => now()]);

        TradePartial::create([
            'trade_id' => $trade->id,
            'mt5_deal_ticket' => 99001,
            'closed_lot_size' => 0.10,
            'close_price' => 1995,
            'close_reason' => 'sl',
            'pips_profit' => -50,
            'gross_money_profit' => -400,
            'commission_money' => 0,
            'swap_money' => 0,
            'net_money_profit' => -400,
            'closed_at' => now(),
        ]);

        app(HealthMonitor::class)->sweep();

        $this->assertContains('daily_loss_limit', $this->keys());
    }

    // =====================================================================
    // LIFECYCLE
    // =====================================================================

    /**
     * The check runs every minute. Without this it would open an incident every minute.
     */
    public function test_a_persisting_condition_stays_one_incident(): void
    {
        $this->heartbeat(['last_seen_at' => now()->subMinutes(10)]);

        app(HealthMonitor::class)->sweep();
        app(HealthMonitor::class)->sweep();
        app(HealthMonitor::class)->sweep();

        $this->assertSame(1, Alert::where('key', 'executor_offline')->count());
    }

    public function test_a_condition_that_clears_is_resolved(): void
    {
        $this->heartbeat(['last_seen_at' => now()->subMinutes(10)]);
        app(HealthMonitor::class)->sweep();

        $this->heartbeat(['last_seen_at' => now()]);
        $this->freshBar();
        app(HealthMonitor::class)->sweep();

        $alert = Alert::where('key', 'executor_offline')->firstOrFail();

        $this->assertNotNull($alert->resolved_at);
        $this->assertSame([], $this->keys());
    }

    /**
     * A recurrence is a new incident, not a reopening - otherwise the history collapses into
     * one row that flaps and nothing can be said about how often it happens.
     */
    public function test_a_recurrence_after_a_resolution_is_a_new_incident(): void
    {
        $this->heartbeat(['last_seen_at' => now()->subMinutes(10)]);
        app(HealthMonitor::class)->sweep();

        $this->heartbeat(['last_seen_at' => now()]);
        $this->freshBar();
        app(HealthMonitor::class)->sweep();

        $this->heartbeat(['last_seen_at' => now()->subMinutes(10)]);
        app(HealthMonitor::class)->sweep();

        $this->assertSame(2, Alert::where('key', 'executor_offline')->count());
        $this->assertSame(1, Alert::where('key', 'executor_offline')->firing()->count());
    }

    // =====================================================================
    // DELIVERY
    // =====================================================================

    public function test_an_alert_is_sent_once_not_once_per_check(): void
    {
        $this->channelAccepts();

        $this->heartbeat(['last_seen_at' => now()->subMinutes(10)]);

        $this->artisan('bot:monitor')->assertSuccessful();
        $this->artisan('bot:monitor')->assertSuccessful();

        Http::assertSentCount(1);
        $this->assertSame(1, Alert::where('key', 'executor_offline')->firstOrFail()->notify_count);
    }

    /**
     * A single message on day one is easy to miss, so a condition that persists repeats -
     * slowly, on the configured interval.
     */
    public function test_a_persisting_alert_is_sent_again_after_the_repeat_interval(): void
    {
        $this->channelAccepts();

        config()->set('alerts.repeat_after_minutes', 60);

        $this->heartbeat(['last_seen_at' => now()->subMinutes(10)]);
        $this->artisan('bot:monitor');

        Alert::where('key', 'executor_offline')->update(['notified_at' => now()->subMinutes(61)]);

        $this->artisan('bot:monitor');

        Http::assertSentCount(2);
    }

    public function test_a_resolution_is_announced(): void
    {
        $this->channelAccepts();

        $this->heartbeat(['last_seen_at' => now()->subMinutes(10)]);
        $this->artisan('bot:monitor');

        $this->heartbeat(['last_seen_at' => now()]);
        $this->freshBar();
        $this->artisan('bot:monitor');

        // The alert, then the all-clear.
        Http::assertSentCount(2);
        $this->assertTrue(Alert::where('key', 'executor_offline')->firstOrFail()->resolution_notified);
    }

    /**
     * An incident nobody was told about does not need an all-clear, and sending one is how a
     * channel turns into noise.
     */
    public function test_an_unannounced_incident_gets_no_all_clear(): void
    {
        $this->channelAccepts();

        $this->heartbeat(['last_seen_at' => now()->subMinutes(10)]);
        $this->artisan('bot:monitor --quiet-channel');

        $this->heartbeat(['last_seen_at' => now()]);
        $this->freshBar();
        $this->artisan('bot:monitor');

        Http::assertNothingSent();
    }

    /**
     * A notification outage must not become a monitoring outage. The incident is still
     * recorded, and a failed send leaves notified_at null so the next sweep retries.
     */
    public function test_a_failing_channel_still_records_the_incident(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['description' => 'chat not found'], 400)]);

        $this->heartbeat(['last_seen_at' => now()->subMinutes(10)]);

        $this->artisan('bot:monitor')->assertSuccessful();

        $alert = Alert::where('key', 'executor_offline')->firstOrFail();

        $this->assertTrue($alert->isFiring());
        $this->assertNull($alert->notified_at);
        $this->assertSame(0, $alert->notify_count);
    }

    public function test_incidents_are_recorded_with_no_channel_configured(): void
    {
        config()->set('alerts.telegram.token', null);
        config()->set('alerts.telegram.chat_id', null);

        $this->heartbeat(['last_seen_at' => now()->subMinutes(10)]);

        $this->artisan('bot:monitor')->assertSuccessful();

        Http::assertNothingSent();
        $this->assertSame(1, Alert::where('key', 'executor_offline')->firing()->count());
    }

    /**
     * Telegram rejects an entire MarkdownV2 message on one unescaped reserved character, and
     * alert bodies are full of them - decimals, percentages, hyphens, parentheses.
     */
    public function test_message_text_is_escaped_for_telegram(): void
    {
        $this->channelAccepts();

        $this->heartbeat(['last_seen_at' => now()->subMinutes(10)]);
        $this->artisan('bot:monitor');

        Http::assertSent(function ($request) {
            $text = $request['text'] ?? '';

            return str_contains($text, '\\.') || ! str_contains($text, '.');
        });
    }

    // =====================================================================
    // EQUITY CURVE
    // =====================================================================

    private function settledTrade(int $ticket, float $net, string $day): void
    {
        Trade::create([
            'user_id' => $this->user->id,
            'strategy_id' => $this->strategy->id,
            'broker_account_id' => $this->account->id,
            'mt5_ticket' => $ticket,
            'symbol' => self::SYMBOL,
            'direction' => 'buy',
            'initial_lot_size' => 0.10,
            'remaining_lot_size' => 0,
            'entry_price' => 2000,
            'sl_price' => 1995,
            'status' => $net < 0 ? 'stopped_out' : 'fully_closed',
            'net_pnl_money' => $net,
            'opened_at' => Carbon::parse($day)->subHour(),
            'closed_at' => Carbon::parse($day),
        ]);
    }

    public function test_the_equity_curve_accumulates_across_days(): void
    {
        $this->settledTrade(1, 100, now()->subDays(3)->toDateTimeString());
        $this->settledTrade(2, -40, now()->subDays(2)->toDateTimeString());
        $this->settledTrade(3, 60, now()->subDay()->toDateTimeString());

        $points = Livewire::actingAs($this->user)->test(DailyChartCard::class)->viewData('points');

        $this->assertSame([100.0, 60.0, 120.0], array_column($points, 'cumulative'));
    }

    /**
     * Same definition of a settled trade as Analytics. The two disagreeing is what made the
     * win rate omit every stop-out.
     */
    public function test_stopped_out_trades_are_on_the_curve(): void
    {
        $this->settledTrade(1, 100, now()->subDays(2)->toDateTimeString());
        $this->settledTrade(2, -250, now()->subDay()->toDateTimeString());

        $geometry = Livewire::actingAs($this->user)->test(DailyChartCard::class)->viewData('geometry');

        $this->assertNotNull($geometry);
        $this->assertEqualsWithDelta(-150.0, $geometry['final'], 0.01);
        $this->assertFalse($geometry['positive']);
    }

    /**
     * A curve that never went negative must not be drawn as if it started from the floor, so
     * the baseline is always inside the viewBox.
     */
    public function test_the_zero_baseline_stays_in_view_on_an_all_positive_curve(): void
    {
        $this->settledTrade(1, 100, now()->subDays(2)->toDateTimeString());
        $this->settledTrade(2, 50, now()->subDay()->toDateTimeString());

        $geometry = Livewire::actingAs($this->user)->test(DailyChartCard::class)->viewData('geometry');

        $this->assertGreaterThanOrEqual(0, $geometry['zero']);
        $this->assertLessThanOrEqual(40, $geometry['zero']);
    }

    public function test_a_single_day_is_shown_as_a_figure_not_a_line(): void
    {
        $this->settledTrade(1, 100, now()->subDay()->toDateTimeString());

        $component = Livewire::actingAs($this->user)->test(DailyChartCard::class);

        $this->assertNull($component->viewData('geometry'));
        $component->assertSee('A curve needs a second day');
    }

    public function test_no_closed_trades_reads_as_empty_rather_than_broken(): void
    {
        Livewire::actingAs($this->user)->test(DailyChartCard::class)
            ->assertOk()
            ->assertSee('No closed trades yet')
            ->assertDontSee('Coming in Phase');
    }
}
