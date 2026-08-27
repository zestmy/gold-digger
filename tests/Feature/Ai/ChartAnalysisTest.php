<?php

namespace Tests\Feature\Ai;

use App\Livewire\Pages\ChartAnalysis;
use App\Models\BotHeartbeat;
use App\Models\BrokerAccount;
use App\Models\Candle;
use App\Models\ChartAnalysis as StoredReading;
use App\Models\Strategy;
use App\Models\User;
use App\Services\Ai\ChartAnalyst;
use App\Services\Indicators\Structure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Reading a chart's structure and proposing a plan.
 *
 * The design under test: levels are measured, and the model chooses among them by number.
 * Asked for "key levels" a language model returns round figures - 2650, 2700 - because
 * those are the numbers text about markets contains, and they are not where the instrument
 * turned. Here it cannot write a price at all, so it cannot write a wrong one.
 */
class ChartAnalysisTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Strategy $strategy;

    private BrokerAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        config(['ai.key' => 'sk-or-test', 'ai.base_url' => 'https://openrouter.ai/api/v1']);

        $this->user = User::factory()->create();
        $this->strategy = Strategy::where('user_id', $this->user->id)->firstOrFail();

        $this->account = BrokerAccount::create([
            'user_id' => $this->user->id, 'label' => 'Demo', 'broker_name' => 'Elev8',
            'account_number' => '1', 'server' => 'Elev8-Demo2', 'is_demo' => true, 'is_active' => true,
        ]);

        // The page resolves its broker account from the connected terminal, which is where
        // every other part of this system gets it. Candles are stored per account, so
        // without a heartbeat the page reads an empty series and reports missing history.
        BotHeartbeat::create([
            'user_id' => $this->user->id, 'broker_account_id' => $this->account->id,
            'source' => 'mql5_ea', 'algo_trading_enabled' => true, 'broker_connected' => true,
            'resolved_symbol' => 'XAUUSD', 'last_seen_at' => now(),
        ]);
    }

    // =====================================================================
    // LEVELS ARE MEASURED
    // =====================================================================

    /**
     * A pivot is a definition, not an opinion.
     */
    public function test_a_price_that_turned_three_times_is_one_level_with_three_touches(): void
    {
        // Three peaks at almost the same price, with troughs between.
        $highs = [10, 11, 20.0, 11, 10, 11, 20.4, 11, 10, 11, 20.2, 11, 10];
        $lows = [9, 8, 15.0, 8, 9, 8, 15.0, 8, 9, 8, 15.0, 8, 9];
        $closes = [9.5, 9.5, 18, 9.5, 9.5, 9.5, 18, 9.5, 9.5, 9.5, 18, 9.5, 9.5];

        $structure = Structure::of($highs, $lows, $closes, 2.0);

        $resistance = collect($structure['levels'])->firstWhere('kind', 'resistance');

        $this->assertSame(3, $resistance['touches'], 'one level tested three times, not three levels');
        $this->assertEqualsWithDelta(20.2, $resistance['price'], 0.3);
    }

    public function test_levels_are_ordered_by_how_often_price_turned_there(): void
    {
        $highs = [10, 11, 20.0, 11, 10, 11, 20.1, 11, 10, 11, 30.0, 11, 10];
        $lows = [9, 8, 15, 8, 9, 8, 15, 8, 9, 8, 15, 8, 9];
        $closes = [9.5, 9.5, 18, 9.5, 9.5, 9.5, 18, 9.5, 9.5, 9.5, 25, 9.5, 9.5];

        $levels = Structure::of($highs, $lows, $closes, 1.0)['levels'];

        // A level price turned at twice is a different object from one it turned at once,
        // and a list sorted by price buries that.
        $this->assertSame(2, $levels[0]['touches']);
    }

    public function test_structure_names_the_sequence(): void
    {
        // Each swing clears the one before it, on both the highs and the lows.
        $highs = [10, 11, 14, 11, 10, 12, 18, 13, 12, 15, 22, 16, 15];
        $lows = [8, 9, 12, 9, 8.5, 10, 16, 11, 10.5, 13, 20, 14, 13.5];
        $closes = [9, 10, 13, 10, 9, 11, 17, 12, 11, 14, 21, 15, 14];

        $rising = Structure::of($highs, $lows, $closes, 1.0);

        $this->assertStringContainsString('rising', $rising['structure']);
    }

    // =====================================================================
    // THE MODEL CHOOSES AMONG THEM
    // =====================================================================

    public function test_the_plan_resolves_level_numbers_into_measured_prices(): void
    {
        $this->bars();
        $this->reads(plan: 'buy', entry: 0, stop: 1, target: 1);

        $result = (new ChartAnalyst)->analyse($this->strategy, $this->account->id, 'XAUUSD', 'M5');

        $reading = $result['reading'];
        $levels = $result['levels'];

        // An oscillation between two prices is two levels, however many times it swings -
        // which is the clustering working, not a shortage of data.
        $this->assertGreaterThanOrEqual(2, count($levels));

        $this->assertSame((float) $levels[0]['price'], $reading['entry_price']);
        $this->assertSame((float) $levels[1]['price'], $reading['stop_price']);
    }

    /**
     * A level not in the list is not a level anybody found.
     */
    public function test_a_level_number_out_of_range_resolves_to_nothing_rather_than_a_price(): void
    {
        $this->bars();
        $this->reads(plan: 'buy', entry: 999, stop: 1, target: 2);

        $reading = (new ChartAnalyst)->analyse($this->strategy, $this->account->id, 'XAUUSD', 'M5')['reading'];

        // Visibly incomplete, which is truthful, and nothing downstream trades it.
        $this->assertNull($reading['entry_price']);
    }

    public function test_waiting_is_a_reading_and_produces_no_levels(): void
    {
        $this->bars();
        $this->reads(plan: 'wait', entry: null, stop: null, target: null);

        $reading = (new ChartAnalyst)->analyse($this->strategy, $this->account->id, 'XAUUSD', 'M5')['reading'];

        $this->assertSame('wait', $reading['plan']);
        $this->assertNull($reading['entry_price']);
    }

    // =====================================================================
    // WHEN THE MODEL IS UNAVAILABLE
    // =====================================================================

    /**
     * The levels were measured, not generated, and they are the more useful half.
     */
    public function test_the_levels_still_come_back_without_a_model(): void
    {
        config(['ai.key' => '']);
        $this->bars();

        $result = (new ChartAnalyst)->analyse($this->strategy, $this->account->id, 'XAUUSD', 'M5');

        $this->assertTrue($result['ok']);
        $this->assertNull($result['reading']);
        $this->assertNotEmpty($result['levels']);
    }

    public function test_a_failed_model_call_still_shows_the_levels(): void
    {
        $this->bars();
        Http::fake(['openrouter.ai/*' => Http::response([], 500)]);

        $result = (new ChartAnalyst)->analyse($this->strategy, $this->account->id, 'XAUUSD', 'M5');

        $this->assertTrue($result['ok']);
        $this->assertNull($result['reading']);
        $this->assertNotEmpty($result['levels']);
    }

    public function test_too_little_history_is_refused_rather_than_read(): void
    {
        $result = (new ChartAnalyst)->analyse($this->strategy, $this->account->id, 'XAUUSD', 'M5');

        $this->assertFalse($result['ok']);
        $this->assertSame([], $result['levels']);
    }

    // =====================================================================
    // THE READING IS KEPT
    // =====================================================================

    /**
     * A reading used to live in a cache for fifteen minutes and then stop existing, which
     * makes "was the analyst any good" unanswerable and "what did it say on Tuesday"
     * impossible. Both are questions a product has to be able to answer.
     */
    public function test_a_reading_is_written_down_with_the_evidence_behind_it(): void
    {
        $this->bars();

        // Levels 0 and 1: the oscillating fixture produces exactly two, so a third index
        // would resolve to null and the reading would be incomplete for a reason that has
        // nothing to do with what is being tested here.
        $this->reads('buy', 0, 1, 1);

        (new ChartAnalyst)->analyse($this->strategy, $this->account->id, 'XAUUSD', 'M5');

        $stored = StoredReading::acrossTenants()->sole();

        $this->assertSame($this->user->id, $stored->user_id);
        $this->assertSame('XAUUSD', $stored->symbol);
        $this->assertSame('M5', $stored->timeframe);
        $this->assertSame('buy', $stored->plan);
        $this->assertSame('bullish', $stored->bias);
        $this->assertSame('test-model', $stored->model);

        // The measured half, stored beside the opinion it produced. Without it a historical
        // reading cannot be re-read: the levels it named would have to be recomputed from
        // bars that have since scrolled out of the window.
        $this->assertNotEmpty($stored->levels);
        $this->assertNotNull($stored->entry_price);
        $this->assertTrue($stored->isComplete());
    }

    /**
     * The refusals are the half that makes the history worth having. An analyst that
     * declined all week during a week that went nowhere was right, and that is invisible
     * from the trades it did not cause.
     */
    public function test_a_refusal_is_kept_too_and_invents_no_prices(): void
    {
        $this->bars();
        $this->reads('wait', null, null, null);

        (new ChartAnalyst)->analyse($this->strategy, $this->account->id, 'XAUUSD', 'M5');

        $stored = StoredReading::acrossTenants()->sole();

        $this->assertSame('wait', $stored->plan);
        $this->assertNull($stored->entry_price);
        $this->assertNull($stored->stop_price);
        $this->assertNull($stored->target_price);
        $this->assertFalse($stored->isComplete());
    }

    /**
     * Asking twice within one bar is the same question - which is already why the cache
     * key is built this way. A history that recorded both would look like a change of mind
     * that never happened.
     */
    public function test_asking_again_within_the_same_bar_does_not_add_a_second_row(): void
    {
        $this->bars();
        $this->reads('buy', 0, 1, 2);

        $analyst = new ChartAnalyst;
        $analyst->analyse($this->strategy, $this->account->id, 'XAUUSD', 'M5');

        // Past the cache, so the model is asked again - and it is still the same bar.
        $analyst->analyse($this->strategy, $this->account->id, 'XAUUSD', 'M5', fresh: true);

        $this->assertSame(1, StoredReading::acrossTenants()->count());
    }

    public function test_a_failed_reading_stores_nothing(): void
    {
        $this->bars();
        Http::fake(['openrouter.ai/*' => Http::response([], 500)]);

        (new ChartAnalyst)->analyse($this->strategy, $this->account->id, 'XAUUSD', 'M5');

        // There was no opinion, so there is nothing to keep. A row here would be a reading
        // nobody made.
        $this->assertSame(0, StoredReading::acrossTenants()->count());
    }

    public function test_the_history_belongs_to_the_tenant_it_was_read_for(): void
    {
        $this->bars();
        $this->reads('buy', 0, 1, 2);

        (new ChartAnalyst)->analyse($this->strategy, $this->account->id, 'XAUUSD', 'M5');

        $stranger = User::factory()->create();

        Livewire::actingAs($stranger)
            ->test(ChartAnalysis::class)
            ->assertViewHas('history', fn ($history) => $history->isEmpty());
    }

    public function test_earlier_readings_are_shown_on_the_page(): void
    {
        $this->bars();
        $this->reads('sell', 0, 1, 2);

        (new ChartAnalyst)->analyse($this->strategy, $this->account->id, 'XAUUSD', 'M5');

        Livewire::actingAs($this->user)
            ->test(ChartAnalysis::class)
            ->assertSee('Earlier readings')
            ->assertSee('Range-bound above support.');
    }

    // =====================================================================
    // WHAT THE CHART DRAWS
    // =====================================================================

    /**
     * Everything drawn on the chart is a number this system computed. A browser deriving
     * its own pivots would eventually disagree with the list the model was shown, and two
     * sets of levels on one page is worse than none.
     */
    public function test_the_proposed_plan_is_drawn_as_entry_stop_and_target(): void
    {
        $this->bars();
        $this->reads('buy', 0, 1, 1);

        $component = Livewire::actingAs($this->user)
            ->test(ChartAnalysis::class)
            ->set('timeframe', 'M5')
            ->set('mode', 'focus')
            ->set('symbol', 'XAUUSD')
            ->call('analyse');

        $titles = array_column($component->get('chartLevels'), 'title');

        $this->assertContains('Entry', $titles);
        $this->assertContains('Stop', $titles);
        $this->assertContains('Target', $titles);
        $this->assertNotEmpty($component->get('candles'));
    }

    /**
     * A refusal has no levels to draw. Drawing a half-filled ladder would render a trade
     * that was never proposed - the same failure the null prices exist to prevent.
     */
    public function test_a_refusal_draws_no_plan_lines(): void
    {
        $this->bars();
        $this->reads('wait', null, null, null);

        $component = Livewire::actingAs($this->user)
            ->test(ChartAnalysis::class)
            ->set('timeframe', 'M5')
            ->set('mode', 'focus')
            ->set('symbol', 'XAUUSD')
            ->call('analyse');

        $titles = array_column($component->get('chartLevels'), 'title');

        $this->assertNotContains('Entry', $titles);
        $this->assertNotContains('Stop', $titles);
    }

    public function test_turning_the_plan_overlay_off_removes_its_lines(): void
    {
        $this->bars();
        $this->reads('buy', 0, 1, 1);

        $component = Livewire::actingAs($this->user)
            ->test(ChartAnalysis::class)
            ->set('timeframe', 'M5')
            ->set('mode', 'focus')
            ->set('symbol', 'XAUUSD')
            ->call('analyse')
            ->set('overlays.plan', false);

        $this->assertNotContains('Entry', array_column($component->get('chartLevels'), 'title'));
    }

    /**
     * Off by default: on a busy instrument this is a dozen horizontal lines, and the three
     * the plan actually uses stop being findable among them.
     */
    public function test_every_measured_level_is_drawn_only_when_asked_for(): void
    {
        $this->bars();
        $this->reads('buy', 0, 1, 1);

        $component = Livewire::actingAs($this->user)
            ->test(ChartAnalysis::class)
            ->set('timeframe', 'M5')
            ->set('mode', 'focus')
            ->set('symbol', 'XAUUSD')
            ->call('analyse');

        $before = count($component->get('chartLevels'));

        $component->set('overlays.levels', true);

        $this->assertGreaterThan($before, count($component->get('chartLevels')));
    }

    // =====================================================================
    // THE PAGE ITSELF
    // =====================================================================

    /**
     * The service was covered and the page was not, so a Blade fault reached production as
     * a 500 that no test could have caught. Rendering it is the cheap half of that lesson.
     */
    public function test_the_page_renders_a_plan(): void
    {
        $this->bars();
        $this->reads(plan: 'buy', entry: 0, stop: 1, target: 1);

        Livewire::actingAs($this->user)
            ->test(ChartAnalysis::class)
            ->set('timeframe', 'M5')
            ->call('focus', 'XAUUSD')
            ->assertOk()
            ->assertSee('Reward against risk');
    }

    public function test_the_page_renders_a_wait(): void
    {
        $this->bars();
        $this->reads(plan: 'wait', entry: null, stop: null, target: null);

        Livewire::actingAs($this->user)
            ->test(ChartAnalysis::class)
            ->set('timeframe', 'M5')
            ->call('focus', 'XAUUSD')
            ->assertOk()
            ->assertSee('No plan proposed');
    }

    /**
     * Opening an instrument is not the same as reading it. The first is free; the second
     * is a model call, and it waits to be asked for.
     */
    public function test_focusing_an_instrument_does_not_read_it_until_asked(): void
    {
        $this->bars();
        Http::fake();

        Livewire::actingAs($this->user)
            ->test(ChartAnalysis::class)
            ->set('timeframe', 'M5')
            ->set('mode', 'focus')
            ->set('symbol', 'XAUUSD')
            ->assertOk()
            ->assertSee('Read this chart');

        Http::assertNothingSent();
    }

    public function test_the_page_renders_before_anything_is_scanned(): void
    {
        $this->bars();

        Livewire::actingAs($this->user)
            ->test(ChartAnalysis::class)
            ->assertOk()
            ->assertSee('Press Scan');
    }

    // =====================================================================
    // HELPERS
    // =====================================================================

    private function reads(string $plan, ?int $entry, ?int $stop, ?int $target): void
    {
        Http::fake(['openrouter.ai/*' => Http::response([
            'model' => 'test-model',
            'choices' => [['message' => ['content' => json_encode([
                'headline' => 'Range-bound above support.',
                'structure' => 'Two clear tests of the lower level.',
                'bias' => 'bullish',
                'plan' => $plan,
                'entry_level' => $entry,
                'stop_level' => $stop,
                'target_level' => $target,
                'reasoning' => 'Because of the levels.',
                'invalidation' => 'A close below the support.',
            ])]]],
        ], 200)]);
    }

    /**
     * Oscillating bars, so there are real pivots to find on both series.
     */
    private function bars(): void
    {
        foreach (['M5', 'H1'] as $timeframe) {
            for ($i = 300; $i >= 0; $i--) {
                $wave = sin($i / 4.0) * 8.0;
                $base = 2650.0 + $wave;

                Candle::create([
                    'user_id' => $this->user->id,
                    'broker_account_id' => $this->account->id,
                    'symbol' => 'XAUUSD',
                    'timeframe' => $timeframe,
                    'open_time' => now()->subMinutes(($timeframe === 'M5' ? 5 : 60) * $i),
                    'open' => $base - 0.5, 'high' => $base + 1.5,
                    'low' => $base - 1.5, 'close' => $base,
                ]);
            }
        }
    }
}
