<?php

namespace Tests\Feature\Monitoring;

use App\Models\Alert;
use App\Models\BotHeartbeat;
use App\Models\BotSettings;
use App\Models\BrokerAccount;
use App\Models\Candle;
use App\Models\Strategy;
use App\Models\Trade;
use App\Models\TradeCommand;
use App\Models\User;
use App\Services\Monitoring\HealthMonitor;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\MakesPriceSeries;
use Tests\TestCase;

/**
 * What happens to an open position when the candle push stops.
 *
 * Trade management runs from the push - on the bar that closed - and until now that was the
 * only place it ran. A push that stopped therefore left every open position with nothing
 * but its broker-side stop, and the dashboard said "feed stalled" at the same level it
 * would for an account with nothing at risk.
 *
 * Two corrections, pinned here: the pass is scheduled as well as pushed, and the stall is
 * critical while there is something for it to cost.
 */
class ScheduledCorrectionsTest extends TestCase
{
    use MakesPriceSeries;
    use RefreshDatabase;

    private User $user;

    private BrokerAccount $account;

    private BotSettings $settings;

    private Strategy $strategy;

    private const SYMBOL = 'XAUUSDm';

    private const ENTRY = 2000.00;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        $this->account = BrokerAccount::create([
            'user_id' => $this->user->id,
            'label' => 'Elev8 Demo',
            'broker_name' => 'Elev8',
            'account_number' => '1',
            'server' => 'Elev8-Demo',
            'is_demo' => true,
            'is_active' => true,
        ]);

        $this->settings = BotSettings::where('user_id', $this->user->id)->firstOrFail();
        $this->settings->update([
            'is_active' => true,
            'allowed_sessions' => null,
            'min_atr_threshold' => null,
        ]);

        $this->strategy = Strategy::where('user_id', $this->user->id)->firstOrFail();
        $this->strategy->update([
            'is_active' => true,
            'symbol' => self::SYMBOL,
            'timeframe_entry' => 'M5',
            'exit_on_reversal' => false,
            'max_holding_bars' => null,
            'trail_trigger_pips' => null,
            'trail_distance_pips' => null,
            'tp1_close_pct' => 50,
        ]);

        BotHeartbeat::create([
            'user_id' => $this->user->id,
            'broker_account_id' => $this->account->id,
            'source' => 'mql5_ea',
            'algo_trading_enabled' => true,
            'broker_connected' => true,
            'resolved_symbol' => self::SYMBOL,
            'pip_size' => 0.10,
            'digits' => 2,
            'pip_value_per_lot' => 10.0,
            'volume_min' => 0.01,
            'volume_step' => 0.01,
            'balance' => 10000.00,
            'last_seen_at' => now(),
        ]);
    }

    private function openTrade(): Trade
    {
        return Trade::create([
            'user_id' => $this->user->id,
            'strategy_id' => $this->strategy->id,
            'broker_account_id' => $this->account->id,
            'mt5_ticket' => 990200,
            'origin' => 'bot',
            'symbol' => self::SYMBOL,
            'direction' => 'buy',
            'initial_lot_size' => 1.00,
            'remaining_lot_size' => 1.00,
            'entry_price' => self::ENTRY,
            'sl_price' => self::ENTRY - 5.00,
            'tp1_price' => self::ENTRY + 3.00,
            'tp2_price' => self::ENTRY + 10.00,
            'tp3_price' => self::ENTRY + 30.00,
            'status' => 'open',
            'opened_at' => now()->subDay(),
        ]);
    }

    private function scheduled(string $command): ?Event
    {
        foreach (app(Schedule::class)->events() as $event) {
            if (str_contains((string) $event->command, $command)) {
                return $event;
            }
        }

        return null;
    }

    // =====================================================================
    // THE PASS IS SCHEDULED, NOT ONLY PUSHED
    // =====================================================================

    public function test_trade_management_runs_every_minute_without_overlapping(): void
    {
        $event = $this->scheduled('trades:manage');

        $this->assertNotNull($event, 'trades:manage should be on the schedule');
        $this->assertSame('* * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }

    /**
     * The property that makes scheduling it safe. The same stored bars have to produce the
     * same commands and nothing more, or a minute-by-minute pass would be a close per minute.
     */
    public function test_running_the_pass_again_with_no_new_bar_queues_nothing_new(): void
    {
        $this->openTrade();

        // Ran through TP1 (+3.00) and stayed there. One rung to take, once.
        $closes = array_fill(0, 60, self::ENTRY);
        $closes[] = self::ENTRY + 5.00;
        $closes[] = self::ENTRY + 4.00;
        $this->seedSeries($closes, 'M5', now()->subMinutes(5), $this->user->id, $this->account->id, self::SYMBOL);

        $this->artisan('trades:manage')->assertSuccessful();
        $this->artisan('trades:manage')->assertSuccessful();
        $this->artisan('trades:manage')->assertSuccessful();

        $closes = TradeCommand::where('type', 'close')->get();

        $this->assertCount(1, $closes);
        $this->assertSame('tp1', $closes->first()->payload['reason']);
        $this->assertSame('pending', $closes->first()->status);
    }

    public function test_the_pass_is_harmless_with_nothing_open(): void
    {
        $this->artisan('trades:manage')->assertSuccessful();

        $this->assertSame(0, TradeCommand::count());
    }

    // =====================================================================
    // A STALLED FEED IS CRITICAL WHILE SOMETHING IS AT RISK
    // =====================================================================

    private function staleBar(): void
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

    private function feedAlert(): ?Alert
    {
        return Alert::where('user_id', $this->user->id)->firing()->where('key', 'feed_stalled:M5')->first();
    }

    public function test_a_stalled_feed_with_nothing_open_is_a_warning(): void
    {
        // Inside the New York session, so the session gate does not swallow the condition.
        $this->travelTo(Carbon::parse('2026-08-24 14:00:00', 'UTC'));
        BotHeartbeat::query()->update(['last_seen_at' => now()]);

        $this->staleBar();

        app(HealthMonitor::class)->sweep();

        $alert = $this->feedAlert();

        $this->assertNotNull($alert);
        $this->assertSame('warning', $alert->level);
    }

    /**
     * Mirrors executor_offline: the same silence is a different fault once there is a
     * position that the ladder, the reversal exit and the trail are no longer watching.
     */
    public function test_a_stalled_feed_with_a_position_open_is_critical(): void
    {
        $this->travelTo(Carbon::parse('2026-08-24 14:00:00', 'UTC'));
        BotHeartbeat::query()->update(['last_seen_at' => now()]);

        $this->staleBar();
        $this->openTrade();

        app(HealthMonitor::class)->sweep();

        $alert = $this->feedAlert();

        $this->assertNotNull($alert);
        $this->assertSame('critical', $alert->level);
        $this->assertStringContainsString('1 position(s) open', $alert->title);
        $this->assertStringContainsString('not being managed', $alert->body);
        $this->assertSame(1, $alert->context['open_positions']);
    }

    /**
     * The level follows the position, not the incident. Closing the position while the feed
     * is still dead should drop the same incident back to a warning, not open a second one.
     */
    public function test_the_level_falls_back_to_a_warning_when_the_position_closes(): void
    {
        $this->travelTo(Carbon::parse('2026-08-24 14:00:00', 'UTC'));
        BotHeartbeat::query()->update(['last_seen_at' => now()]);

        $this->staleBar();
        $trade = $this->openTrade();

        app(HealthMonitor::class)->sweep();
        $this->assertSame('critical', $this->feedAlert()->level);

        $trade->update(['status' => 'closed']);

        app(HealthMonitor::class)->sweep();

        $this->assertSame('warning', $this->feedAlert()->level);
        $this->assertSame(1, Alert::where('user_id', $this->user->id)->where('key', 'feed_stalled:M5')->count());
    }
}
