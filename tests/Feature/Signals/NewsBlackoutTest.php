<?php

namespace Tests\Feature\Signals;

use App\Models\BotHeartbeat;
use App\Models\BotSettings;
use App\Models\BrokerAccount;
use App\Models\Candle;
use App\Models\MarketEvent;
use App\Models\Signal;
use App\Models\Strategy;
use App\Models\TradeCommand;
use App\Models\User;
use App\Services\Backtest\Backtester;
use App\Services\Backtest\MarketAssumptions;
use App\Services\Monitoring\HealthMonitor;
use App\Services\Strategy\SignalGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\MakesPriceSeries;
use Tests\TestCase;

/**
 * The news blackout.
 *
 * `bot_settings.news_filter_enabled` shipped as a settings-page toggle that appeared in no
 * decision path anywhere: a user could switch it on, read it back on, and be trading straight
 * through NFP. The first test here is the one that would have caught that, and everything else
 * exists so the fix cannot rot back into decoration.
 *
 * Two properties are worth more than the individual cases.
 *
 * The blackout is compared against the *whole bar*, not its open. The entry happens after the
 * bar closes, so a filter that only tests the open leaves a hole one timeframe deep on the
 * near side of every release - and that hole is exactly where the pre-release spread widening
 * lives. `test_an_event_landing_inside_the_bar_blocks_it` pins that.
 *
 * The filter fails open. No calendar means no blackout, because the alternative is a failed
 * import silently halting the bot. That is only defensible because `HealthMonitor` says so out
 * loud, which is why the alert is tested here rather than in the monitoring suite.
 */
class NewsBlackoutTest extends TestCase
{
    use MakesPriceSeries;
    use RefreshDatabase;

    private User $user;

    private BrokerAccount $account;

    private Strategy $strategy;

    private BotSettings $settings;

    /** Open time of the last M5 bar, so the bar itself spans 13:00:00 - 13:05:00 UTC. */
    private Carbon $lastBar;

    private const SYMBOL = 'XAUUSDm';

    protected function setUp(): void
    {
        parent::setUp();

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
        $this->settings->update([
            'is_active' => true,
            'risk_percentage' => 1.00,
            'max_concurrent_trades' => 3,
            'allowed_sessions' => null,
            'min_atr_threshold' => null,
            'news_filter_enabled' => true,
            'news_blackout_before_minutes' => 15,
            'news_blackout_after_minutes' => 15,
        ]);

        $this->strategy = Strategy::where('user_id', $this->user->id)->firstOrFail();
        $this->strategy->update([
            'is_active' => true,
            'symbol' => 'XAUUSD',
            'adx_threshold' => 0,
        ]);

        $this->lastBar = Carbon::parse('2026-03-10 13:00:00', 'UTC');

        $this->heartbeat();
    }

    // =====================================================================
    // THE BUG THIS EXISTS TO FIX
    // =====================================================================

    public function test_a_high_impact_event_inside_the_window_blocks_the_signal(): void
    {
        // 13:10 is inside 13:00 + 15 minutes.
        $this->event('Non-Farm Employment Change', '2026-03-10 13:10:00');

        $this->seedBullishSetup();

        $signal = $this->generate();

        $this->assertNotNull($signal, 'The setup must still be recorded, not dropped.');
        $this->assertSame('news_blackout', $signal->skip_reason);
        $this->assertFalse($signal->was_executed);
        $this->assertSame(0, TradeCommand::where('type', 'open')->count());
    }

    /**
     * A skip reason on its own invites an argument. The row has to name the release.
     */
    public function test_the_blocked_signal_records_which_release_blocked_it(): void
    {
        $this->event('Non-Farm Employment Change', '2026-03-10 13:10:00');

        $this->seedBullishSetup();

        $signal = $this->generate();

        $this->assertArrayHasKey('news_event', $signal->features);
        $this->assertStringContainsString('Non-Farm Employment Change', $signal->features['news_event']);
        $this->assertStringContainsString('USD', $signal->features['news_event']);
    }

    /**
     * The near-side hole, and the reason the bar is treated as an interval.
     *
     * The bar opens at 13:00 and closes at 13:05, which is when the entry would be placed.
     * An event at 13:19 opens its window at 13:04 - after the bar's open, before its close.
     * Testing the open alone lets this through and then fills into the blackout.
     */
    public function test_an_event_landing_inside_the_bar_blocks_it(): void
    {
        $this->event('FOMC Statement', '2026-03-10 13:19:00');

        $this->seedBullishSetup();

        $signal = $this->generate();

        $this->assertSame('news_blackout', $signal->skip_reason);
    }

    public function test_an_event_outside_the_window_does_not_block(): void
    {
        // Window opens at 13:16, four minutes after the 13:05 bar close - no overlap.
        $this->event('FOMC Statement', '2026-03-10 13:31:00');

        $this->seedBullishSetup();

        $signal = $this->generate();

        $this->assertNull($signal->skip_reason);
        $this->assertSame(1, TradeCommand::where('type', 'open')->count());
    }

    // =====================================================================
    // WHAT COUNTS AS AN EVENT
    // =====================================================================

    public function test_a_low_impact_event_does_not_block(): void
    {
        $this->event('Loan Officer Survey', '2026-03-10 13:10:00', impact: 'low');

        $this->seedBullishSetup();

        $this->assertNull($this->generate()->skip_reason);
    }

    /**
     * Gold is priced in dollars. A euro-area release moves XAUEUR, and blacking out gold for
     * it would cost setups for nothing.
     */
    public function test_an_event_in_another_currency_does_not_block(): void
    {
        $this->event('ECB Press Conference', '2026-03-10 13:10:00', currency: 'EUR');

        $this->seedBullishSetup();

        $this->assertNull($this->generate()->skip_reason);
    }

    // =====================================================================
    // THE SWITCH, AND THE WIDTH
    // =====================================================================

    public function test_the_filter_does_nothing_when_switched_off(): void
    {
        $this->event('Non-Farm Employment Change', '2026-03-10 13:10:00');
        $this->settings->update(['news_filter_enabled' => false]);

        $this->seedBullishSetup();

        $this->assertNull($this->generate()->skip_reason);
    }

    /**
     * On, but configured to nothing. Blocking the instant of the release anyway would be
     * inventing a window the user deliberately set to zero.
     */
    public function test_a_zero_width_window_blocks_nothing(): void
    {
        $this->event('Non-Farm Employment Change', '2026-03-10 13:00:00');
        $this->settings->update([
            'news_blackout_before_minutes' => 0,
            'news_blackout_after_minutes' => 0,
        ]);

        $this->seedBullishSetup();

        $this->assertNull($this->generate()->skip_reason);
    }

    /**
     * The two sides are separate settings because they are separate risks, so a window that
     * is wide after and narrow before has to behave that way.
     */
    public function test_the_two_sides_of_the_window_are_independent(): void
    {
        // 40 minutes before the bar. Only an `after` window this wide can reach it.
        $this->event('CPI m/m', '2026-03-10 12:20:00');
        $this->settings->update([
            'news_blackout_before_minutes' => 5,
            'news_blackout_after_minutes' => 60,
        ]);

        $this->seedBullishSetup();

        $this->assertSame('news_blackout', $this->generate()->skip_reason);

        // The same event with a short `after` window no longer reaches.
        Signal::query()->delete();
        $this->settings->update(['news_blackout_after_minutes' => 5]);

        $this->assertNull($this->generate()->skip_reason);
    }

    // =====================================================================
    // FAILING OPEN, AND THE ALERT THAT MAKES THAT SAFE
    // =====================================================================

    public function test_an_empty_calendar_blocks_nothing(): void
    {
        $this->seedBullishSetup();

        $this->assertNull($this->generate()->skip_reason);
        $this->assertSame(1, TradeCommand::where('type', 'open')->count());
    }

    public function test_an_empty_calendar_raises_a_stale_alert(): void
    {
        $conditions = app(HealthMonitor::class)->conditionsFor($this->user);

        $keys = array_column($conditions, 'key');

        $this->assertContains('news_calendar_stale', $keys);
    }

    public function test_a_calendar_reaching_far_enough_ahead_raises_nothing(): void
    {
        MarketEvent::create([
            'source' => 'test',
            'title' => 'Something next week',
            'currency' => 'USD',
            'impact' => 'high',
            'scheduled_at' => now()->addDays(3),
        ]);

        $keys = array_column(app(HealthMonitor::class)->conditionsFor($this->user), 'key');

        $this->assertNotContains('news_calendar_stale', $keys);
    }

    public function test_no_stale_alert_when_the_filter_is_switched_off(): void
    {
        $this->settings->update(['news_filter_enabled' => false]);

        $keys = array_column(app(HealthMonitor::class)->conditionsFor($this->user), 'key');

        $this->assertNotContains('news_calendar_stale', $keys);
    }

    // =====================================================================
    // THE BACKTESTER APPLIES THE SAME RULE
    // =====================================================================

    /**
     * The whole reason this filter is arithmetic rather than a judgement call: it has to
     * replay. A backtest that ignores the blackout measures a strategy nobody is running, and
     * would credit it with entries the live system refuses.
     */
    public function test_the_backtester_applies_the_blackout(): void
    {
        $closes = $this->crossCloses('buy');

        // Room after the cross, since an entry fills on the bar after the signal.
        $closes = array_merge($closes, array_fill(0, 6, end($closes)));

        $this->seedBars($closes, 'M5');
        $this->seedBars($this->trendCloses(80, rising: true), 'H1');

        $report = $this->backtest();
        $this->assertSame(0, $report->skips['news_blackout'] ?? 0, 'Baseline: nothing blocked.');

        // Black out the entire series.
        $bars = $this->series('M5');
        $first = $bars[0]->open_time;
        $last = end($bars)->open_time;

        for ($at = $first->copy(); $at->lessThanOrEqualTo($last); $at->addMinutes(20)) {
            $this->event('Rolling blackout '.$at->timestamp, $at->toDateTimeString());
        }

        $blocked = $this->backtest();

        $this->assertGreaterThan(0, $blocked->skips['news_blackout'] ?? 0);
    }

    // =====================================================================
    // HELPERS
    // =====================================================================

    private function event(
        string $title,
        string $at,
        string $currency = 'USD',
        string $impact = 'high',
    ): MarketEvent {
        return MarketEvent::create([
            'source' => 'test',
            'title' => $title,
            'currency' => $currency,
            'impact' => $impact,
            'scheduled_at' => Carbon::parse($at, 'UTC'),
        ]);
    }

    private function generate(): ?Signal
    {
        return app(SignalGenerator::class)->generate($this->strategy->fresh(), $this->account->id);
    }

    private function backtest()
    {
        return app(Backtester::class)->run(
            $this->strategy->fresh(),
            $this->series('M5'),
            $this->series('H1'),
            new MarketAssumptions(
                pipSize: 0.10,
                pipValuePerLot: 10.0,
                pointSize: 0.01,
                spreadPips: 0.0,
                slippagePips: 0.0,
                commissionPerLot: 0.0,
                startingBalance: 10000.0,
            ),
            $this->settings->fresh(),
        );
    }

    /** @return array<int, Candle> */
    private function series(string $timeframe): array
    {
        return Candle::where('broker_account_id', $this->account->id)
            ->where('timeframe', $timeframe)
            ->orderBy('open_time')
            ->get()
            ->all();
    }

    private function heartbeat(): void
    {
        BotHeartbeat::updateOrCreate(
            ['user_id' => $this->user->id, 'source' => 'mql5_ea'],
            [
                'broker_account_id' => $this->account->id,
                'algo_trading_enabled' => true,
                'broker_connected' => true,
                'resolved_symbol' => self::SYMBOL,
                'pip_size' => 0.10,
                'digits' => 2,
                'pip_value_per_lot' => 10.0,
                'balance' => 10000.00,
                'equity' => 10000.00,
                'last_seen_at' => now(),
            ],
        );
    }

    private function seedBullishSetup(): void
    {
        $this->seedBars($this->crossCloses('buy'), 'M5');
        $this->seedBars($this->trendCloses(80, rising: true), 'H1');
    }

    /** @param array<int, float> $closes */
    private function seedBars(array $closes, string $timeframe): void
    {
        $this->seedSeries($closes, $timeframe, $this->lastBar, $this->user->id, $this->account->id, self::SYMBOL);
    }
}
