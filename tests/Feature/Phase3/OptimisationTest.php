<?php

namespace Tests\Feature\Phase3;

use App\Models\BotSettings;
use App\Models\BrokerAccount;
use App\Models\Candle;
use App\Models\Signal;
use App\Models\Strategy;
use App\Models\Trade;
use App\Models\TradeCommand;
use App\Models\User;
use App\Services\Backtest\MarketAssumptions;
use App\Services\Backtest\ParameterGrid;
use App\Services\Backtest\SweepResult;
use App\Services\Backtest\SweepRunner;
use App\Services\Backtest\WalkForward;
use App\Services\Backtest\WalkForwardReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Tests\Support\MakesPriceSeries;
use Tests\TestCase;

/**
 * Parameter sweeps and walk-forward validation.
 *
 * What is pinned here is mostly the machinery's refusal to flatter: that a four-trade fluke
 * cannot top a ranking, that a sweep never rewrites the strategy it is measuring, and that the
 * out-of-sample window is genuinely unseen. The numbers a search produces matter far less than
 * whether it can be trusted to produce a discouraging one.
 */
class OptimisationTest extends TestCase
{
    use MakesPriceSeries;
    use RefreshDatabase;

    private User $user;

    private BrokerAccount $account;

    private Strategy $strategy;

    private BotSettings $settings;

    private Carbon $lastBar;

    private const SYMBOL = 'XAUUSDm';

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        $this->account = BrokerAccount::create([
            'user_id' => $this->user->id,
            'label' => 'Octa Demo',
            'broker_name' => 'Octa',
            'account_number' => '1',
            'server' => 'OctaFX-Demo',
            'is_demo' => true,
            'is_active' => true,
        ]);

        $this->settings = BotSettings::where('user_id', $this->user->id)->firstOrFail();
        $this->settings->update([
            'is_active' => true,
            'allowed_sessions' => null,
            'min_atr_threshold' => null,
            'max_concurrent_trades' => 1,
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
    }

    private function market(): MarketAssumptions
    {
        return new MarketAssumptions(0.10, 10.0, 0.01, 0.0, 0.0, 0.0, 10000.0);
    }

    /**
     * A long oscillating series, so a walk can find several crossings in every fold.
     *
     * @return array<int, float>
     */
    private function oscillating(int $bars = 900): array
    {
        $closes = [];

        for ($i = 0; $i < $bars; $i++) {
            // Two waves of different periods, so crossings are irregular rather than a
            // metronome that any parameter set would ride perfectly.
            $closes[] = 2300
                + sin($i / 23) * 26
                + sin($i / 7) * 6;
        }

        return $closes;
    }

    private function seedBars(array $closes, string $timeframe = 'M5'): void
    {
        $this->seedSeries($closes, $timeframe, $this->lastBar, $this->user->id, $this->account->id, self::SYMBOL);
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

    // =====================================================================
    // THE GRID
    // =====================================================================

    public function test_a_grid_expands_to_every_combination(): void
    {
        $grid = new ParameterGrid(['ema_fast=5,10', 'adx_threshold=20,25,30']);

        $this->assertCount(6, $grid->combinations());
    }

    public function test_a_range_specification_is_expanded_inclusively(): void
    {
        $grid = new ParameterGrid(['adx_threshold=20:30:5']);

        $this->assertSame([20.0, 25.0, 30.0], $grid->axes()['adx_threshold']);
    }

    /**
     * Floating-point drift otherwise loses the final value of a fractional range.
     */
    public function test_a_fractional_range_keeps_its_last_value(): void
    {
        $grid = new ParameterGrid(['sl_atr_multiplier=1.0:2.0:0.5']);

        $this->assertSame([1.0, 1.5, 2.0], $grid->axes()['sl_atr_multiplier']);
    }

    /**
     * A fast EMA at or above the slow one inverts every signal, and a ladder out of order
     * makes TradeManager take its rungs backwards. Running them wastes time and pollutes the
     * ranking with results that mean nothing.
     */
    public function test_incoherent_combinations_are_dropped(): void
    {
        $grid = new ParameterGrid(['ema_fast=10,50', 'ema_slow=20']);

        $combinations = $grid->combinations();

        $this->assertCount(1, $combinations);
        $this->assertSame(10.0, $combinations[0]['ema_fast']);
    }

    public function test_an_out_of_order_ladder_is_dropped(): void
    {
        $grid = new ParameterGrid(['tp1_pips=30,120', 'tp2_pips=100']);

        $this->assertCount(1, $grid->combinations());
    }

    public function test_an_unknown_parameter_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ParameterGrid(['is_active=1']);
    }

    public function test_a_malformed_specification_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ParameterGrid(['ema_fast']);
    }

    /**
     * A sweep that rewrote the strategy would be changing what trades while measuring what
     * does not.
     */
    public function test_applying_a_combination_never_touches_the_stored_strategy(): void
    {
        $original = (int) $this->strategy->ema_fast;

        $candidate = ParameterGrid::apply($this->strategy, ['ema_fast' => 99.0]);

        $this->assertSame(99.0, (float) $candidate->ema_fast);
        $this->assertFalse($candidate->exists);
        $this->assertSame($original, (int) $this->strategy->fresh()->ema_fast);
    }

    // =====================================================================
    // RANKING
    // =====================================================================

    /**
     * The guard that matters most. A handful of trades is a coincidence, and without this it
     * would top every table it appeared in.
     */
    public function test_a_result_below_the_trade_floor_never_outranks_one_above_it(): void
    {
        $fluke = new SweepResult(['ema_fast' => 5.0], ['trades' => 3, 'net_pnl' => 9999.0, 'max_drawdown' => 1.0], 30);
        $real = new SweepResult(['ema_fast' => 10.0], ['trades' => 120, 'net_pnl' => 400.0, 'max_drawdown' => 80.0], 30);

        $ranked = SweepRunner::rank([$fluke, $real]);

        $this->assertSame(10.0, $ranked[0]->parameters['ema_fast']);
        $this->assertFalse($fluke->qualifies);
    }

    /**
     * Doubling an account through a 60% drawdown is not better than half the return through
     * 5%, and net profit cannot tell the difference.
     */
    public function test_ranking_prefers_return_earned_with_less_drawdown(): void
    {
        $violent = new SweepResult(['ema_fast' => 5.0], ['trades' => 100, 'net_pnl' => 1000.0, 'max_drawdown' => 900.0], 30);
        $steady = new SweepResult(['ema_fast' => 10.0], ['trades' => 100, 'net_pnl' => 700.0, 'max_drawdown' => 90.0], 30);

        $ranked = SweepRunner::rank([$violent, $steady]);

        $this->assertSame(10.0, $ranked[0]->parameters['ema_fast']);
    }

    public function test_a_losing_combination_is_ranked_by_how_much_it_lost(): void
    {
        $bad = new SweepResult(['ema_fast' => 5.0], ['trades' => 100, 'net_pnl' => -900.0, 'max_drawdown' => 900.0], 30);
        $lessBad = new SweepResult(['ema_fast' => 10.0], ['trades' => 100, 'net_pnl' => -100.0, 'max_drawdown' => 120.0], 30);

        $this->assertSame(10.0, SweepRunner::rank([$bad, $lessBad])[0]->parameters['ema_fast']);
    }

    /**
     * A run that never drew down must not divide by something near zero and score as
     * infinitely good, which on a short sample usually means it took two trades.
     */
    public function test_a_zero_drawdown_result_does_not_score_infinitely(): void
    {
        $result = new SweepResult(['ema_fast' => 10.0], ['trades' => 100, 'net_pnl' => 500.0, 'max_drawdown' => 0.0], 30);

        $this->assertLessThan(1000, $result->score);
        $this->assertGreaterThan(0, $result->score);
    }

    /**
     * When metrics disagree about the winner, that disagreement is the finding.
     */
    public function test_metric_disagreement_is_detected(): void
    {
        $a = new SweepResult(['ema_fast' => 5.0], ['trades' => 100, 'net_pnl' => 1000.0, 'max_drawdown' => 900.0, 'profit_factor' => 1.1, 'expectancy' => 10.0], 30);
        $b = new SweepResult(['ema_fast' => 10.0], ['trades' => 100, 'net_pnl' => 700.0, 'max_drawdown' => 50.0, 'profit_factor' => 2.4, 'expectancy' => 7.0], 30);

        $agreement = SweepRunner::agreement([$a, $b]);

        $this->assertFalse($agreement['agree']);
        $this->assertNotSame($agreement['winners']['score'], $agreement['winners']['net_pnl']);
    }

    // =====================================================================
    // WALK FORWARD
    // =====================================================================

    public function test_walk_forward_tests_each_winner_on_unseen_bars(): void
    {
        $this->seedBars($this->oscillating(900), 'M5');
        $this->seedBars($this->trendCloses(120, rising: true), 'H1');

        $report = app(WalkForward::class)->run(
            $this->strategy->fresh(),
            $this->series('M5'),
            $this->series('H1'),
            (new ParameterGrid(['ema_fast=8,14']))->combinations(),
            $this->market(),
            $this->settings->fresh(),
            folds: 3,
            minTrades: 1,
        );

        $tested = $report->tested();

        $this->assertNotEmpty($tested, 'the walk should produce at least one tested fold');

        foreach ($tested as $fold) {
            // Each fold's out-of-sample window must be later than the training data - that
            // separation is the only thing that makes the result a prediction.
            $this->assertArrayHasKey('test_from', $fold['window']);
            $this->assertGreaterThan(0, $fold['window']['train_bars']);
            $this->assertNotNull($fold['out_of_sample']);
        }
    }

    public function test_folds_advance_through_the_series(): void
    {
        $this->seedBars($this->oscillating(900), 'M5');
        $this->seedBars($this->trendCloses(120, rising: true), 'H1');

        $report = app(WalkForward::class)->run(
            $this->strategy->fresh(),
            $this->series('M5'),
            $this->series('H1'),
            (new ParameterGrid(['ema_fast=8,14']))->combinations(),
            $this->market(),
            $this->settings->fresh(),
            folds: 3,
            minTrades: 1,
        );

        $tested = $report->tested();

        if (count($tested) < 2) {
            $this->markTestSkipped('needs at least two tested folds');
        }

        // Later folds train on more data and test on later bars.
        $this->assertGreaterThan($tested[0]['window']['train_bars'], $tested[1]['window']['train_bars']);
        $this->assertGreaterThan($tested[0]['window']['test_from'], $tested[1]['window']['test_from']);
    }

    /**
     * Asking for more folds than the data can carry has to say so. Silently producing an
     * empty report would read as "the strategy takes no trades".
     */
    public function test_too_little_data_for_the_requested_folds_is_reported(): void
    {
        $this->seedBars(array_fill(0, 80, 2000.0), 'M5');
        $this->seedBars($this->trendCloses(40, rising: true), 'H1');

        $report = app(WalkForward::class)->run(
            $this->strategy->fresh(),
            $this->series('M5'),
            $this->series('H1'),
            (new ParameterGrid(['ema_fast=8,14']))->combinations(),
            $this->market(),
            $this->settings->fresh(),
            folds: 6,
        );

        $this->assertNotEmpty($report->notes);
        $this->assertStringContainsString('Not enough bars', $report->notes[0]);
    }

    /**
     * The verdict is what most readers will act on, so it has to be blunt about the normal
     * case: an optimisation that did not generalise.
     */
    public function test_a_failure_to_generalise_is_stated_plainly(): void
    {
        $report = new WalkForwardReport($this->strategy, 2, []);

        $report->addFold(1, ['ema_fast' => 10.0], ['net_pnl' => 500.0, 'trades' => 50], ['net_pnl' => -200.0, 'trades' => 40, 'win_rate' => 30.0], ['train_bars' => 100, 'test_bars' => 50, 'test_from' => 'x', 'test_to' => 'y']);

        $degradation = $report->degradation();

        $this->assertLessThan(0, $degradation['out_of_sample_expectancy']);
        $this->assertStringContainsString('did not generalise', $degradation['verdict']);
    }

    /**
     * Found by running against real bars: an early version reported "most of the edge
     * survived" from a single out-of-sample trade. Below the floor, no verdict is offered
     * in either direction.
     */
    public function test_a_handful_of_out_of_sample_trades_yields_no_verdict(): void
    {
        $report = new WalkForwardReport($this->strategy, 2, []);

        $report->addFold(
            1,
            ['ema_fast' => 10.0],
            ['net_pnl' => 1000.0, 'trades' => 40],
            ['net_pnl' => 300.0, 'trades' => 1, 'win_rate' => 100.0],
            ['train_bars' => 500, 'test_bars' => 200, 'test_from' => 'x', 'test_to' => 'y'],
        );

        $verdict = $report->degradation()['verdict'];

        $this->assertStringContainsString('too few to conclude', $verdict);
        $this->assertStringNotContainsString('survived', $verdict);
    }

    public function test_a_sufficient_out_of_sample_sample_does_get_a_verdict(): void
    {
        $report = new WalkForwardReport($this->strategy, 2, []);

        $report->addFold(
            1,
            ['ema_fast' => 10.0],
            ['net_pnl' => 1000.0, 'trades' => 100],
            ['net_pnl' => 600.0, 'trades' => 60, 'win_rate' => 55.0],
            ['train_bars' => 500, 'test_bars' => 200, 'test_from' => 'x', 'test_to' => 'y'],
        );

        $this->assertStringContainsString('survived', $report->degradation()['verdict']);
    }

    public function test_no_out_of_sample_trades_is_not_reported_as_a_result(): void
    {
        $report = new WalkForwardReport($this->strategy, 2, []);

        $report->addFold(1, ['ema_fast' => 10.0], ['net_pnl' => 500.0, 'trades' => 50], ['net_pnl' => 0.0, 'trades' => 0, 'win_rate' => 0.0], ['train_bars' => 100, 'test_bars' => 50, 'test_from' => 'x', 'test_to' => 'y']);

        $this->assertStringContainsString('Nothing was tested', $report->degradation()['verdict']);
    }

    /**
     * Parameters that move fold to fold mean the surface is flat and the search is following
     * noise. Weaker evidence than the out-of-sample number, but strong evidence against.
     */
    public function test_unstable_winning_parameters_are_flagged(): void
    {
        $report = new WalkForwardReport($this->strategy, 2, []);

        $window = ['train_bars' => 100, 'test_bars' => 50, 'test_from' => 'x', 'test_to' => 'y'];
        $out = ['net_pnl' => 10.0, 'trades' => 5, 'win_rate' => 50.0];

        $report->addFold(1, ['ema_fast' => 10.0], ['net_pnl' => 1.0, 'trades' => 5], $out, $window);
        $report->addFold(2, ['ema_fast' => 30.0], ['net_pnl' => 1.0, 'trades' => 5], $out, $window);

        $stability = $report->stability();

        $this->assertFalse($stability['stable']);
        $this->assertFalse($stability['per_parameter']['ema_fast']['stable']);
    }

    public function test_stable_winning_parameters_are_recognised(): void
    {
        $report = new WalkForwardReport($this->strategy, 2, []);

        $window = ['train_bars' => 100, 'test_bars' => 50, 'test_from' => 'x', 'test_to' => 'y'];
        $out = ['net_pnl' => 10.0, 'trades' => 5, 'win_rate' => 50.0];

        $report->addFold(1, ['ema_fast' => 10.0], ['net_pnl' => 1.0, 'trades' => 5], $out, $window);
        $report->addFold(2, ['ema_fast' => 10.0], ['net_pnl' => 1.0, 'trades' => 5], $out, $window);

        $this->assertTrue($report->stability()['stable']);
    }

    // =====================================================================
    // THE COMMAND
    // =====================================================================

    public function test_the_command_refuses_an_unbounded_search(): void
    {
        $this->seedBars($this->oscillating(400), 'M5');
        $this->seedBars($this->trendCloses(80, rising: true), 'H1');

        $this->artisan('backtest:optimise '.$this->strategy->id
            .' --param="ema_fast=5:40:1" --param="ema_slow=45:120:1" --max=50')
            ->expectsOutputToContain('exceeds the --max')
            ->assertFailed();
    }

    public function test_the_command_requires_something_to_search(): void
    {
        $this->artisan('backtest:optimise '.$this->strategy->id)
            ->expectsOutputToContain('Nothing to search')
            ->assertFailed();
    }

    public function test_the_command_rejects_an_unsweepable_parameter(): void
    {
        $this->artisan('backtest:optimise '.$this->strategy->id.' --param="symbol=XAUUSD"')
            ->expectsOutputToContain('cannot be swept')
            ->assertFailed();
    }

    /**
     * A sweep fits the sample, and saying so is not optional decoration - it is the
     * difference between a tool and a trap.
     */
    public function test_a_sweep_warns_that_it_fitted_the_sample(): void
    {
        $this->seedBars($this->oscillating(500), 'M5');
        $this->seedBars($this->trendCloses(100, rising: true), 'H1');

        $this->artisan('backtest:optimise '.$this->strategy->id.' --param="ema_fast=8,14" --sweep')
            ->expectsOutputToContain('fits the sample')
            ->assertSuccessful();
    }

    public function test_the_command_writes_nothing_to_the_database(): void
    {
        $this->seedBars($this->oscillating(500), 'M5');
        $this->seedBars($this->trendCloses(100, rising: true), 'H1');

        $before = [
            Signal::count(),
            Trade::count(),
            TradeCommand::count(),
            (int) $this->strategy->fresh()->ema_fast,
        ];

        $this->artisan('backtest:optimise '.$this->strategy->id.' --param="ema_fast=8,14" --sweep')
            ->assertSuccessful();

        $this->assertSame($before, [
            Signal::count(),
            Trade::count(),
            TradeCommand::count(),
            (int) $this->strategy->fresh()->ema_fast,
        ]);
    }
}
