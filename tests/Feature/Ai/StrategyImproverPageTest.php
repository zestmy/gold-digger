<?php

namespace Tests\Feature\Ai;

use App\Jobs\RunStrategyImprovement;
use App\Livewire\Pages\StrategyImprover;
use App\Models\Strategy;
use App\Models\StrategyImprovement as ImprovementRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The Improver page and the job behind it.
 *
 * The page shows an LLM's suggestions next to backtest numbers, on the same screen as a
 * live account. What these pin down is that it cannot present a proposal which reads well
 * as one that performed well: the verdict precedes the table, a thin sample suppresses any
 * "better" badge whatever the arithmetic says, and there is no path from this page to a
 * changed strategy.
 */
class StrategyImproverPageTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Strategy $strategy;

    protected function setUp(): void
    {
        parent::setUp();

        config(['ai.key' => 'sk-or-test']);

        $this->user = User::factory()->create();
        $this->strategy = Strategy::where('user_id', $this->user->id)->firstOrFail();

        $this->actingAs($this->user);
    }

    private function makeRun(array $overrides = []): ImprovementRun
    {
        return ImprovementRun::create($overrides + [
            'user_id' => $this->user->id,
            'strategy_id' => $this->strategy->id,
            'status' => ImprovementRun::STATUS_DONE,
            'thin' => false,
            'verdict' => 'Most of the edge survived out of sample.',
            'baseline' => ['trades' => 40, 'net_pnl' => 100.0, 'win_rate' => 55.0, 'expectancy' => 2.5, 'folds_tested' => 4, 'folds_profitable' => 3],
            'proposed' => ['trades' => 44, 'net_pnl' => 140.0, 'win_rate' => 57.0, 'expectancy' => 3.2, 'folds_tested' => 4, 'folds_profitable' => 4],
            'proposals' => [['parameters' => ['adx_threshold' => 20.0], 'rationale' => 'Loosen the ADX gate.']],
            'model' => 'anthropic/claude-sonnet-4.5',
            'finished_at' => now(),
        ]);
    }

    public function test_it_queues_a_run_rather_than_blocking_the_request(): void
    {
        Queue::fake();

        Livewire::test(StrategyImprover::class)->call('queueRun');

        Queue::assertPushed(RunStrategyImprovement::class);
        $this->assertDatabaseHas('strategy_improvements', [
            'user_id' => $this->user->id,
            'status' => ImprovementRun::STATUS_QUEUED,
        ]);
    }

    /**
     * Two concurrent walk-forwards on a 1GB droplet is an out-of-memory kill, and the
     * kernel's usual choice of victim is MySQL.
     */
    public function test_it_refuses_a_second_concurrent_run(): void
    {
        Queue::fake();
        $this->makeRun(['status' => ImprovementRun::STATUS_RUNNING]);

        Livewire::test(StrategyImprover::class)
            ->call('queueRun')
            ->assertHasErrors('strategyId');

        Queue::assertNothingPushed();
    }

    public function test_it_will_not_queue_a_strategy_belonging_to_someone_else(): void
    {
        Queue::fake();
        $other = User::factory()->create();
        $theirs = Strategy::where('user_id', $other->id)->firstOrFail();

        Livewire::test(StrategyImprover::class)
            ->set('strategyId', $theirs->id)
            ->call('queueRun')
            ->assertHasErrors('strategyId');

        Queue::assertNothingPushed();
    }

    public function test_it_is_off_without_a_key(): void
    {
        Queue::fake();
        config(['ai.key' => null]);

        Livewire::test(StrategyImprover::class)
            ->assertSee('OPENROUTER_API_KEY')
            ->call('queueRun')
            ->assertHasErrors('strategyId');

        Queue::assertNothingPushed();
    }

    // =====================================================================
    // HOW A RESULT IS PRESENTED
    // =====================================================================

    public function test_a_thin_result_leads_with_the_verdict_and_claims_nothing(): void
    {
        $this->makeRun([
            'thin' => true,
            'verdict' => 'Only 9 out-of-sample trades across 3 folds - too few to conclude anything.',
        ]);

        Livewire::test(StrategyImprover::class)
            ->assertSee('too few to conclude anything')
            ->assertSee('Nothing below supports a change');
    }

    /**
     * The arithmetic says the proposal is better. The sample says nobody can tell.
     */
    public function test_a_thin_result_never_reads_as_beating_the_baseline(): void
    {
        $run = $this->makeRun(['thin' => true]);

        $this->assertGreaterThan(
            $run->baseline['expectancy'],
            $run->proposed['expectancy'],
            'Fixture sanity: the proposal does look better on paper.',
        );
        $this->assertFalse($run->beatsBaseline(), 'A thin sample must never read as an improvement.');
    }

    public function test_a_solid_result_may_beat_the_baseline(): void
    {
        $this->assertTrue($this->makeRun(['thin' => false])->beatsBaseline());
    }

    public function test_it_admits_the_proposed_column_is_a_selected_winner(): void
    {
        $this->makeRun();

        Livewire::test(StrategyImprover::class)->assertSee('best candidate in each fold');
    }

    public function test_there_is_no_apply_button(): void
    {
        $this->makeRun();

        // Applying is a deliberate act on the Strategies page, having read why. A one-click
        // apply beside a persuasive rationale is how an unmeasured change reaches an account.
        Livewire::test(StrategyImprover::class)
            ->assertDontSee('wire:click="apply')
            ->assertSee('Strategies');
    }

    public function test_a_failed_run_shows_why(): void
    {
        $this->makeRun([
            'status' => ImprovementRun::STATUS_FAILED,
            'error' => 'Allowed memory size of 268435456 bytes exhausted',
        ]);

        // "It failed" on a dashboard is the same as silence, and an out-of-memory kill
        // reads very differently from a rejected API key.
        Livewire::test(StrategyImprover::class)->assertSee('memory size');
    }

    public function test_the_page_renders(): void
    {
        $this->makeRun();

        $this->get(route('strategies.improve'))->assertOk()->assertSee('Strategy Improver');
    }
}
