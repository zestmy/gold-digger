<?php

namespace Tests\Feature\Maintenance;

use App\Models\Alert;
use App\Models\BotLog;
use App\Models\BrokerAccount;
use App\Models\Candle;
use App\Models\ChartAnalysis;
use App\Models\Signal;
use App\Models\Strategy;
use App\Models\Trade;
use App\Models\User;
use App\Services\Strategy\StrategyEvaluator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Retention.
 *
 * The only scheduled command in this application that deletes anything, which makes the
 * important tests here the negative ones. Measured, `candles` was 91% of the database and
 * grows per broker account rather than being shared - so something had to give - but the
 * trade record and the "was any of this any good" evidence are not it.
 *
 * The other load-bearing behaviour is that bars are counted rather than dated. A 90-day
 * cutoff leaves 25,000 M5 bars and 1,500 H1 bars: the same policy barely touching one
 * series and starving another.
 */
class PruneOldDataTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private BrokerAccount $account;

    private Strategy $strategy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->strategy = Strategy::where('user_id', $this->user->id)->firstOrFail();

        $this->account = BrokerAccount::create([
            'user_id' => $this->user->id, 'label' => 'Demo', 'broker_name' => 'Elev8',
            'account_number' => '1', 'server' => 'Elev8-Demo', 'is_demo' => true, 'is_active' => true,
        ]);
    }

    // =====================================================================
    // WHAT IT WILL NOT TOUCH
    // =====================================================================

    /**
     * The financial record. However old, whatever the setting.
     */
    public function test_trades_are_never_pruned(): void
    {
        $trade = Trade::query()->forceCreate([
            'user_id' => $this->user->id,
            'strategy_id' => $this->strategy->id,
            'broker_account_id' => $this->account->id,
            'symbol' => 'XAUUSD', 'direction' => 'buy',
            'initial_lot_size' => 0.01, 'remaining_lot_size' => 0.0,
            'entry_price' => 2000, 'sl_price' => 1990, 'status' => 'closed',
            'opened_at' => now()->subYears(3), 'closed_at' => now()->subYears(3),
            'created_at' => now()->subYears(3),
        ]);

        $this->artisan('data:prune')->assertSuccessful();

        $this->assertDatabaseHas('trades', ['id' => $trade->id]);
    }

    /**
     * `signals` and `chart_analyses` store refusals as carefully as decisions, precisely so
     * "was the filter too strict" and "was the analyst any good" stay answerable. Pruning
     * them to save disk would undo the reason they exist.
     */
    public function test_the_decision_history_is_never_pruned(): void
    {
        $signal = Signal::query()->forceCreate([
            'strategy_id' => $this->strategy->id,
            'symbol' => 'XAUUSD', 'timeframe' => 'M5', 'direction' => 'buy',
            'entry_price' => 2000, 'sl_price' => 1990,
            'skip_reason' => 'adx_below_threshold',
            'generated_at' => now()->subYears(3),
            'created_at' => now()->subYears(3),
        ]);

        $analysis = ChartAnalysis::query()->forceCreate([
            'user_id' => $this->user->id,
            'symbol' => 'XAUUSD', 'timeframe' => 'M5',
            'bar_open_time' => now()->subYears(3),
            'bias' => 'neutral', 'plan' => 'wait',
            'headline' => 'Nothing here.', 'structure' => 's', 'reasoning' => 'r', 'invalidation' => 'i',
            'created_at' => now()->subYears(3),
        ]);

        $this->artisan('data:prune')->assertSuccessful();

        $this->assertDatabaseHas('signals', ['id' => $signal->id]);
        $this->assertDatabaseHas('chart_analyses', ['id' => $analysis->id]);
    }

    /**
     * How long something has been broken is the most interesting thing about it.
     */
    public function test_an_unresolved_alert_is_never_pruned_however_old(): void
    {
        $firing = $this->alert(resolvedAt: null, age: 400);
        $resolved = $this->alert(resolvedAt: now()->subDays(400), age: 400);

        $this->artisan('data:prune')->assertSuccessful();

        $this->assertDatabaseHas('alerts', ['id' => $firing->id]);
        $this->assertDatabaseMissing('alerts', ['id' => $resolved->id]);
    }

    // =====================================================================
    // BARS ARE COUNTED, NOT DATED
    // =====================================================================

    public function test_a_series_under_the_limit_is_left_alone(): void
    {
        config(['trading.retention.candle_bars_per_series' => 100]);
        $this->seedBars('M5', 40);

        $this->artisan('data:prune')->assertSuccessful();

        $this->assertSame(40, Candle::acrossTenants()->count());
    }

    public function test_a_series_over_the_limit_keeps_its_newest_bars(): void
    {
        config(['trading.retention.candle_bars_per_series' => 30]);
        $this->seedBars('M5', 100);

        $this->artisan('data:prune')->assertSuccessful();

        $kept = Candle::acrossTenants()->orderBy('open_time')->get();

        $this->assertCount(30, $kept);

        // The newest thirty, not an arbitrary thirty. Bar 100 is the most recent.
        $this->assertSame(
            Carbon::parse('2026-01-01 00:00:00')->addMinutes(5 * 70)->toDateTimeString(),
            $kept->first()->open_time->toDateTimeString(),
        );
    }

    /**
     * The reason retention is counted per series rather than per table. Trimming to "the
     * newest N bars" across the table would keep whichever series is busiest and delete
     * the other outright.
     */
    public function test_each_series_is_trimmed_on_its_own_count(): void
    {
        config(['trading.retention.candle_bars_per_series' => 30]);

        $this->seedBars('M5', 100);
        $this->seedBars('H1', 20);

        $this->artisan('data:prune')->assertSuccessful();

        $this->assertSame(30, Candle::acrossTenants()->where('timeframe', 'M5')->count());
        $this->assertSame(20, Candle::acrossTenants()->where('timeframe', 'H1')->count(), 'the quiet series is untouched');
    }

    public function test_two_accounts_are_trimmed_independently(): void
    {
        config(['trading.retention.candle_bars_per_series' => 30]);

        $other = BrokerAccount::create([
            'user_id' => $this->user->id, 'label' => 'Second', 'broker_name' => 'Elev8',
            'account_number' => '2', 'server' => 'Elev8-Demo', 'is_demo' => true, 'is_active' => false,
        ]);

        $this->seedBars('M5', 100);
        $this->seedBars('M5', 10, account: $other);

        $this->artisan('data:prune')->assertSuccessful();

        $this->assertSame(30, Candle::acrossTenants()->where('broker_account_id', $this->account->id)->count());
        $this->assertSame(10, Candle::acrossTenants()->where('broker_account_id', $other->id)->count());
    }

    /**
     * The floor the default is set from.
     *
     * It used to be the improver's 20,000, because deep history had nowhere else to come
     * from. `MarketData::forBacktest()` fetches that on demand now and stores none of it,
     * so what has to survive retention is only what still reads stored bars - and the
     * deepest of those is the evaluator's 300, shared with the dashboard chart.
     */
    public function test_the_default_keeps_well_clear_of_what_still_reads_stored_bars(): void
    {
        $this->assertGreaterThanOrEqual(
            StrategyEvaluator::LOOKBACK_BARS * 5,
            (int) config('trading.retention.candle_bars_per_series'),
            'Retention has to leave the evaluator and the chart surfaces comfortable room.',
        );
    }

    public function test_retention_can_be_switched_off_entirely(): void
    {
        config(['trading.retention.candle_bars_per_series' => 0]);
        $this->seedBars('M5', 100);

        $this->artisan('data:prune')->assertSuccessful();

        $this->assertSame(100, Candle::acrossTenants()->count());
    }

    // =====================================================================
    // AGE-BASED TABLES
    // =====================================================================

    public function test_old_logs_go_and_recent_ones_stay(): void
    {
        config(['trading.retention.bot_log_days' => 30]);

        $old = BotLog::create(['user_id' => $this->user->id, 'level' => 'info', 'source' => 'x', 'message' => 'old']);
        $old->forceFill(['created_at' => now()->subDays(90)])->saveQuietly();

        BotLog::create(['user_id' => $this->user->id, 'level' => 'info', 'source' => 'x', 'message' => 'recent']);

        $this->artisan('data:prune')->assertSuccessful();

        $this->assertDatabaseMissing('bot_logs', ['message' => 'old']);
        $this->assertDatabaseHas('bot_logs', ['message' => 'recent']);
    }

    // =====================================================================
    // DRY RUN
    // =====================================================================

    /**
     * A prune that turns out to have been wrong is not undone by editing the setting
     * afterwards, so the reporting path has to delete nothing at all.
     */
    public function test_a_dry_run_deletes_nothing_and_still_reports(): void
    {
        config(['trading.retention.candle_bars_per_series' => 30]);
        $this->seedBars('M5', 100);

        $this->artisan('data:prune', ['--dry' => true])
            ->expectsOutputToContain('Dry run')
            ->assertSuccessful();

        $this->assertSame(100, Candle::acrossTenants()->count());
    }

    // =====================================================================
    // FIXTURES
    // =====================================================================

    private function seedBars(string $timeframe, int $count, ?BrokerAccount $account = null): void
    {
        $account ??= $this->account;
        $minutes = $timeframe === 'M5' ? 5 : 60;
        $start = Carbon::parse('2026-01-01 00:00:00');
        $rows = [];

        for ($i = 0; $i < $count; $i++) {
            $rows[] = [
                'user_id' => $this->user->id,
                'broker_account_id' => $account->id,
                'symbol' => 'XAUUSD',
                'timeframe' => $timeframe,
                'open_time' => $start->copy()->addMinutes($i * $minutes),
                'open' => 2000, 'high' => 2001, 'low' => 1999, 'close' => 2000,
                'tick_volume' => 10, 'source' => 'test',
                'created_at' => now(), 'updated_at' => now(),
            ];
        }

        Candle::insert($rows);
    }

    private function alert(?Carbon $resolvedAt, int $age): Alert
    {
        return Alert::query()->forceCreate([
            'user_id' => $this->user->id,
            'key' => 'executor_offline_'.$age.'_'.($resolvedAt === null ? 'firing' : 'resolved'),
            'level' => 'warning',
            'title' => 'Executor offline',
            'body' => 'No heartbeat.',
            'first_seen_at' => now()->subDays($age),
            'last_seen_at' => now()->subDays($age),
            'resolved_at' => $resolvedAt,
        ]);
    }
}
