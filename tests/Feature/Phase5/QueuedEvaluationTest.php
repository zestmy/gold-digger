<?php

namespace Tests\Feature\Phase5;

use App\Jobs\EvaluateNewBars;
use App\Models\Alert;
use App\Models\BotHeartbeat;
use App\Models\BotSettings;
use App\Models\BotToken;
use App\Models\BrokerAccount;
use App\Models\Signal;
use App\Models\Strategy;
use App\Models\Trade;
use App\Models\TradeCommand;
use App\Models\User;
use App\Services\Monitoring\HealthMonitor;
use App\Services\Strategy\SignalGenerator;
use App\Services\Strategy\TradeManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\Support\MakesPriceSeries;
use Tests\TestCase;

/**
 * Moving strategy evaluation off the executor's request.
 *
 * The switch is off by default, and the tests here are mostly about the two ways turning it on
 * could go wrong: the work quietly changing when it moves, and nothing draining the queue.
 *
 * That second one is the reason the feature was worth hesitating over. A worker that is not
 * running produces no error anywhere - bars arrive, jobs accumulate, and the bot stops trading
 * while the executor heartbeats happily and the signals page simply stays empty.
 */
class QueuedEvaluationTest extends TestCase
{
    use MakesPriceSeries;
    use RefreshDatabase;

    private User $user;

    private BrokerAccount $account;

    private Strategy $strategy;

    private string $plaintext;

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
            'account_number' => '1',
            'server' => 'Elev8-Demo',
            'is_demo' => true,
            'is_active' => true,
        ]);

        [$this->plaintext] = BotToken::generate($this->user, 'Test VPS', $this->account);

        BotSettings::where('user_id', $this->user->id)->update([
            'is_active' => true,
            'allowed_sessions' => null,
            'min_atr_threshold' => null,
        ]);

        $this->strategy = Strategy::where('user_id', $this->user->id)->firstOrFail();
        $this->strategy->update([
            'is_active' => true,
            'symbol' => self::SYMBOL,
            'adx_threshold' => 0,
            'exit_on_reversal' => false,
            'max_holding_bars' => null,
        ]);

        $this->lastBar = Carbon::parse('2026-03-10 13:00:00', 'UTC');

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

    private function auth(): array
    {
        return ['Authorization' => 'Bearer '.$this->plaintext];
    }

    private function push(array $closes, string $timeframe = 'M5'): TestResponse
    {
        return $this->postJson('/api/v1/bot/candles', [
            'symbol' => self::SYMBOL,
            'timeframe' => $timeframe,
            'bars' => $this->barPayloads($closes, $timeframe, $this->lastBar),
        ], $this->auth());
    }

    /**
     * A crossover on the final bar.
     *
     * Unlike the backtester - which walks history and needs a bar after the cross to fill on -
     * the live generator only ever asks about the most recent closed bar. Appending bars after
     * the crossing one moves it out of view and produces no signal at all.
     *
     * @return array<int, float>
     */
    private function setupSeries(): array
    {
        return $this->crossCloses('buy');
    }

    // =====================================================================
    // THE SWITCH
    // =====================================================================

    /**
     * The default has to stay inline. Turning this on without a worker stops the bot trading,
     * so it is not something an upgrade should do on somebody's behalf.
     */
    public function test_evaluation_runs_inline_by_default(): void
    {
        Queue::fake();

        $this->push($this->trendCloses(80, rising: true), 'H1');
        $this->push($this->setupSeries(), 'M5')->assertJsonPath('queued', false);

        Queue::assertNothingPushed();
        $this->assertSame(1, Signal::count());
    }

    public function test_enabling_the_switch_dispatches_instead_of_evaluating(): void
    {
        config()->set('trading.queue_evaluation', true);
        Queue::fake();

        $this->push($this->trendCloses(80, rising: true), 'H1');
        $response = $this->push($this->setupSeries(), 'M5');

        $response->assertJsonPath('queued', true);

        Queue::assertPushed(EvaluateNewBars::class);

        // Nothing evaluated in the request - that is the entire point.
        $this->assertSame(0, Signal::count());
    }

    /**
     * Reporting an empty signal list alongside `queued` would read as "no signals found",
     * which is a different statement from "not yet looked".
     */
    public function test_a_queued_response_does_not_report_an_empty_result(): void
    {
        config()->set('trading.queue_evaluation', true);
        Queue::fake();

        $this->push($this->trendCloses(80, rising: true), 'H1');

        $this->push($this->setupSeries(), 'M5')
            ->assertJsonPath('queued', true)
            ->assertJsonPath('signals', [])
            ->assertJsonPath('managed', []);
    }

    /**
     * A push with nothing new in it evaluates nothing, queued or not.
     */
    public function test_a_push_with_no_new_bars_queues_nothing(): void
    {
        config()->set('trading.queue_evaluation', true);

        $this->push($this->trendCloses(80, rising: true), 'H1');
        $this->push($this->setupSeries(), 'M5');

        Queue::fake();
        $this->push($this->setupSeries(), 'M5')->assertJsonPath('new_bars', 0);

        Queue::assertNothingPushed();
    }

    // =====================================================================
    // THE JOB DOES THE SAME WORK
    // =====================================================================

    /**
     * The switch must change *when* the work happens and never *what* it does, or a decision
     * to queue becomes a change of strategy behaviour nobody asked for.
     */
    public function test_the_job_produces_the_same_signal_the_inline_path_would(): void
    {
        $this->push($this->trendCloses(80, rising: true), 'H1');
        $this->push($this->setupSeries(), 'M5');

        $inline = Signal::firstOrFail();

        // Clear the result and re-run through the job against the same stored bars.
        Signal::query()->delete();
        TradeCommand::query()->delete();

        (new EvaluateNewBars($this->user->id, 'M5', $this->account->id))
            ->handle(app(TradeManager::class), app(SignalGenerator::class));

        $queued = Signal::firstOrFail();

        $this->assertSame($inline->direction, $queued->direction);
        $this->assertSame($inline->skip_reason, $queued->skip_reason);
        $this->assertEquals($inline->generated_at, $queued->generated_at);
    }

    /**
     * Positions are managed before entries are considered, so an exit reaches the executor
     * ahead of the entry that replaces it.
     */
    public function test_the_job_manages_positions_before_looking_for_entries(): void
    {
        $this->strategy->update(['max_holding_bars' => 5]);

        $this->push($this->trendCloses(80, rising: true), 'H1');
        $this->push($this->setupSeries(), 'M5');

        Trade::create([
            'user_id' => $this->user->id,
            'strategy_id' => $this->strategy->id,
            'broker_account_id' => $this->account->id,
            'mt5_ticket' => 991001,
            'origin' => 'bot',
            'symbol' => self::SYMBOL,
            'direction' => 'buy',
            'initial_lot_size' => 0.10,
            'remaining_lot_size' => 0.10,
            'entry_price' => 2000,
            'sl_price' => 1995,
            'status' => 'open',
            'opened_at' => $this->lastBar->copy()->subDay(),
        ]);

        TradeCommand::query()->delete();

        (new EvaluateNewBars($this->user->id, 'M5', $this->account->id))
            ->handle(app(TradeManager::class), app(SignalGenerator::class));

        $close = TradeCommand::where('type', 'close')->first();

        $this->assertNotNull($close, 'the holding limit should have produced an exit');
        $this->assertSame('time_exit', $close->payload['reason']);
    }

    /**
     * The executor re-pushes a trailing window on every poll. Without uniqueness a burst would
     * queue a job apiece, all doing identical work against identical data.
     */
    public function test_the_job_is_unique_per_account_and_timeframe(): void
    {
        $job = new EvaluateNewBars($this->user->id, 'M5', $this->account->id);
        $same = new EvaluateNewBars($this->user->id, 'M5', $this->account->id);
        $other = new EvaluateNewBars($this->user->id, 'H1', $this->account->id);

        $this->assertSame($job->uniqueId(), $same->uniqueId());
        $this->assertNotSame($job->uniqueId(), $other->uniqueId());
    }

    /**
     * Two accounts are two independent executors. Making one wait for the other would be a
     * queue of the system's own making.
     */
    public function test_two_accounts_do_not_block_each_other(): void
    {
        $other = BrokerAccount::create([
            'user_id' => $this->user->id,
            'label' => 'Second',
            'broker_name' => 'Elev8',
            'account_number' => '2',
            'server' => 'Elev8-Demo',
            'is_demo' => true,
            'is_active' => false,
        ]);

        $a = new EvaluateNewBars($this->user->id, 'M5', $this->account->id);
        $b = new EvaluateNewBars($this->user->id, 'M5', $other->id);

        $this->assertNotSame($a->uniqueId(), $b->uniqueId());
    }

    // =====================================================================
    // A QUEUE NOBODY IS DRAINING
    // =====================================================================

    /**
     * phpunit.xml pins the queue to `sync`, where there is no backlog to inspect. The stall
     * check deliberately says nothing about drivers whose queue it cannot see, so these tests
     * have to put it on the driver the deployment actually uses.
     */
    private function useDatabaseQueue(): void
    {
        config()->set('trading.queue_evaluation', true);
        config()->set('queue.default', 'database');
    }

    private function stubJob(int $ageSeconds): void
    {
        DB::table('jobs')->insert([
            'queue' => config('trading.queue', 'strategy'),
            'payload' => '{}',
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => now()->subSeconds($ageSeconds)->timestamp,
            'created_at' => now()->subSeconds($ageSeconds)->timestamp,
        ]);
    }

    /**
     * The alert that makes the switch safe to offer. Without it, a stopped worker is invisible
     * - the executor is healthy, bars are arriving, and nothing trades.
     */
    public function test_a_queue_nobody_is_draining_raises_an_alert(): void
    {
        $this->useDatabaseQueue();

        $this->stubJob(3600);

        app(HealthMonitor::class)->sweep();

        $alert = Alert::where('key', 'queue_stalled')->firing()->first();

        $this->assertNotNull($alert);
        $this->assertSame('critical', $alert->level);
        $this->assertStringContainsString('queue:work', $alert->body);
    }

    /**
     * A job that has been waiting a moment is a working queue, not a stalled one.
     */
    public function test_a_briefly_waiting_job_is_not_a_stall(): void
    {
        $this->useDatabaseQueue();

        $this->stubJob(30);

        app(HealthMonitor::class)->sweep();

        $this->assertSame(0, Alert::where('key', 'queue_stalled')->count());
    }

    /**
     * A stale job is a stale job, whatever it was going to do.
     *
     * This used to assert silence when evaluation is inline, on the reasoning that there
     * was then no worker to be missing. That stopped being true when the Improver page
     * began queueing runs: with the old gate a dead worker left improvement runs at
     * `queued` for ever with nothing saying why - the same silent failure this check
     * exists to prevent, just relocated.
     *
     * It warns rather than pages, because nothing that trades depends on it. Levelling a
     * stuck backtest the same as a stalled entry is how a critical alert stops meaning
     * anything.
     */
    public function test_a_stalled_queue_warns_even_when_evaluation_is_inline(): void
    {
        config()->set('queue.default', 'database');
        config()->set('trading.queue_evaluation', false);

        $this->stubJob(3600);

        app(HealthMonitor::class)->sweep();

        $alert = Alert::where('key', 'queue_stalled')->first();

        $this->assertNotNull($alert);
        $this->assertSame('warning', $alert->level);
        $this->assertStringContainsString('improvement', $alert->body);
    }

    /**
     * With evaluation queued it is a trading fault, and reads as one.
     */
    public function test_a_stalled_queue_is_critical_when_it_carries_trading_decisions(): void
    {
        config()->set('queue.default', 'database');
        config()->set('trading.queue_evaluation', true);

        $this->stubJob(3600);

        app(HealthMonitor::class)->sweep();

        $this->assertSame('critical', Alert::where('key', 'queue_stalled')->first()?->level);
    }

    /**
     * An installation that queues nothing has no jobs, so this cannot be noise.
     */
    public function test_an_idle_queue_with_no_jobs_reports_nothing(): void
    {
        config()->set('queue.default', 'database');
        config()->set('trading.queue_evaluation', false);

        app(HealthMonitor::class)->sweep();

        $this->assertSame(0, Alert::where('key', 'queue_stalled')->count());
    }

    public function test_an_empty_queue_raises_nothing(): void
    {
        $this->useDatabaseQueue();

        app(HealthMonitor::class)->sweep();

        $this->assertSame(0, Alert::where('key', 'queue_stalled')->count());
    }

    /**
     * A job already claimed by a worker is being worked on, however long it takes.
     */
    public function test_a_reserved_job_is_not_counted_as_waiting(): void
    {
        $this->useDatabaseQueue();

        DB::table('jobs')->insert([
            'queue' => config('trading.queue', 'strategy'),
            'payload' => '{}',
            'attempts' => 1,
            'reserved_at' => now()->timestamp,
            'available_at' => now()->subHour()->timestamp,
            'created_at' => now()->subHour()->timestamp,
        ]);

        app(HealthMonitor::class)->sweep();

        $this->assertSame(0, Alert::where('key', 'queue_stalled')->count());
    }

    /**
     * The stall clears when a worker comes back, like every other condition.
     */
    public function test_the_stall_resolves_once_the_queue_drains(): void
    {
        $this->useDatabaseQueue();

        $this->stubJob(3600);
        app(HealthMonitor::class)->sweep();

        DB::table('jobs')->delete();
        app(HealthMonitor::class)->sweep();

        $this->assertNotNull(Alert::where('key', 'queue_stalled')->firstOrFail()->resolved_at);
    }
}
