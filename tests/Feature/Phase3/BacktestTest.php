<?php

namespace Tests\Feature\Phase3;

use App\Models\BotHeartbeat;
use App\Models\BotSettings;
use App\Models\BrokerAccount;
use App\Models\Candle;
use App\Models\Signal;
use App\Models\Strategy;
use App\Models\Trade;
use App\Models\TradeCommand;
use App\Models\User;
use App\Services\Backtest\Backtester;
use App\Services\Backtest\MarketAssumptions;
use App\Services\Strategy\StrategyEvaluator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\MakesPriceSeries;
use Tests\TestCase;

/**
 * The backtester.
 *
 * Two properties matter more than any metric it produces.
 *
 * The first is that it calls the *same* StrategyEvaluator the live path calls, so a result
 * transfers. A backtester with its own copy of the entry rules eventually describes a strategy
 * nobody is running.
 *
 * The second is that every ambiguity resolves against the trade. A backtest is only worth
 * running if it can say no - and the ways one quietly says yes are all here: filling at the
 * rung instead of the bar close, taking the target when the bar also spanned the stop, and
 * entering at the price the decision was made from.
 */
class BacktestTest extends TestCase
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
            'risk_percentage' => 1.0,
            'max_concurrent_trades' => 1,
        ]);

        $this->strategy = Strategy::where('user_id', $this->user->id)->firstOrFail();
        $this->strategy->update([
            'is_active' => true,
            // No heartbeat in these tests, so the resolved symbol falls back to the
            // strategy's own - which has to match what the bars are stored under.
            'symbol' => self::SYMBOL,
            'adx_threshold' => 0,
            'exit_on_reversal' => false,
            'max_holding_bars' => null,
        ]);

        $this->lastBar = Carbon::parse('2026-03-10 13:00:00', 'UTC');
    }

    private function market(array $overrides = []): MarketAssumptions
    {
        return new MarketAssumptions(
            pipSize: 0.10,
            pipValuePerLot: 10.0,
            pointSize: 0.01,
            spreadPips: $overrides['spreadPips'] ?? 0.0,
            slippagePips: $overrides['slippagePips'] ?? 0.0,
            commissionPerLot: $overrides['commissionPerLot'] ?? 0.0,
            startingBalance: $overrides['startingBalance'] ?? 10000.0,
            volumeStep: $overrides['volumeStep'] ?? 0.01,
            volumeMin: $overrides['volumeMin'] ?? 0.01,
            // Zero unless a test is about latency, so every other assertion here is about
            // the thing it names. Real runs get the queue's measured figure, or ten
            // seconds - see MarketAssumptions::fromHeartbeat().
            latencySeconds: $overrides['latencySeconds'] ?? 0.0,
        );
    }

    /** The stored bar a simulated trade filled on. */
    private function barAt($openTime): Candle
    {
        return Candle::where('broker_account_id', $this->account->id)
            ->where('timeframe', 'M5')
            ->where('open_time', $openTime)
            ->firstOrFail();
    }

    /** @param array<int, float> $closes */
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

    private function backtest(?MarketAssumptions $market = null)
    {
        return app(Backtester::class)->run(
            $this->strategy->fresh(),
            $this->series('M5'),
            $this->series('H1'),
            $market ?? $this->market(),
            $this->settings->fresh(),
        );
    }

    /**
     * The crossover fixture with a few bars after it.
     *
     * `crossCloses` puts the cross on the very last bar, which the walk correctly refuses to
     * enter on - there is no next bar to fill against, and filling on the signal bar's own
     * close would be look-ahead. So the tests that need an entry append room for one.
     *
     * @return array{closes: array<int, float>, crossIndex: int}
     */
    private function crossThenRoom(int $extra = 6): array
    {
        $closes = $this->crossCloses('buy');
        $crossIndex = count($closes) - 1;
        $last = end($closes);

        for ($i = 1; $i <= $extra; $i++) {
            $closes[] = $last + ($i * 0.4);
        }

        return ['closes' => $closes, 'crossIndex' => $crossIndex];
    }

    /**
     * A long decline, a sharp rally that clears every target, then a drift.
     *
     * @return array<int, float>
     */
    private function winningSeries(): array
    {
        $closes = $this->crossCloses('buy');

        // After the cross, keep climbing so TP1, TP2 and TP3 are all reached in turn.
        $last = end($closes);
        for ($i = 1; $i <= 40; $i++) {
            $closes[] = $last + ($i * 3.0);
        }

        return $closes;
    }

    // =====================================================================
    // IT USES THE LIVE EVALUATOR
    // =====================================================================

    /**
     * The property the whole design rests on. If the backtester found entries the live
     * evaluator would not, its results would describe a different strategy.
     */
    public function test_entries_match_what_the_live_evaluator_would_have_signalled(): void
    {
        ['closes' => $closes, 'crossIndex' => $crossIndex] = $this->crossThenRoom();

        $this->seedBars($closes, 'M5');
        $this->seedBars($this->trendCloses(80, rising: true), 'H1');

        $entry = $this->series('M5');
        $trend = $this->series('H1');

        // The live path, asked about the bar the cross actually happened on.
        $liveSetup = app(StrategyEvaluator::class)->evaluate(
            $this->strategy->fresh(),
            array_slice($entry, 0, $crossIndex + 1),
            $trend,
        );

        $this->assertNotNull($liveSetup, 'fixture should produce a live signal on the cross bar');

        // The walk reaches the same bar and takes the same trade.
        $report = $this->backtest();

        $this->assertSame(1, $report->entriesTaken);
        $this->assertSame($liveSetup->direction, ($report->trades[0] ?? $report->unclosed[0])->direction);
    }

    public function test_a_series_with_no_crossover_takes_no_trades(): void
    {
        $this->seedBars($this->trendCloses(300, rising: true), 'M5');
        $this->seedBars($this->trendCloses(80, rising: true), 'H1');

        $report = $this->backtest();

        $this->assertSame(0, $report->entriesTaken);
        $this->assertSame(0, $report->metrics()['trades']);
    }

    public function test_too_little_history_is_reported_rather_than_silently_empty(): void
    {
        $this->seedBars(array_fill(0, 20, 2000.0), 'M5');
        $this->seedBars($this->trendCloses(80, rising: true), 'H1');

        $report = $this->backtest();

        $this->assertNotEmpty($report->notes);
        $this->assertStringContainsString('Not enough bars', $report->notes[0]);
    }

    // =====================================================================
    // PESSIMISM
    // =====================================================================

    /**
     * The signal is produced *from* a bar's close, so filling at that close is trading on
     * the information that produced it. The next bar's open is the first reachable price.
     */
    public function test_entry_fills_on_the_bar_after_the_signal(): void
    {
        $closes = $this->winningSeries();

        $this->seedBars($closes, 'M5');
        $this->seedBars($this->trendCloses(80, rising: true), 'H1');

        $report = $this->backtest();
        $trade = $report->trades[0] ?? $report->unclosed[0];

        $bars = $this->series('M5');
        $signalBarIndex = count($this->crossCloses('buy')) - 1;

        // The fixture writes open == close, so the fill price is the next bar's close.
        $this->assertEqualsWithDelta(
            (float) $bars[$signalBarIndex + 1]->open,
            $trade->entryPrice,
            0.001,
        );
    }

    /**
     * Spread and slippage are adverse on the way in. A backtest that skips them shows a
     * profit for almost any strategy.
     */
    public function test_costs_move_the_entry_against_the_trade(): void
    {
        $closes = $this->winningSeries();

        $this->seedBars($closes, 'M5');
        $this->seedBars($this->trendCloses(80, rising: true), 'H1');

        $free = $this->backtest($this->market());
        $costly = $this->backtest($this->market(['spreadPips' => 4.0, 'slippagePips' => 1.0]));

        $a = ($free->trades[0] ?? $free->unclosed[0])->entryPrice;
        $b = ($costly->trades[0] ?? $costly->unclosed[0])->entryPrice;

        // A buy pays the spread on the way in, so it enters higher.
        $this->assertGreaterThan($a, $b);
        $this->assertEqualsWithDelta(0.5, $b - $a, 0.001);
    }

    /**
     * A sell enters at bid and pays the spread on the way *out*, buying back at ask. Before
     * this test existed a sell paid no spread at all - the exit was filled at bid - and
     * every short in every backtest was one spread better than the trade it modelled.
     */
    public function test_a_sell_pays_the_spread_on_the_way_out(): void
    {
        // A short held for a fixed number of bars, then closed at market: the one exit
        // whose price is the bar close by construction, so the spread is the only thing
        // that can separate the two runs.
        $this->strategy->update([
            'exit_on_reversal' => false,
            'max_holding_bars' => 3,
            'tp3_r' => 50,
            'sl_atr_multiplier' => 50,
        ]);

        $closes = $this->crossCloses('sell');
        $last = end($closes);

        for ($i = 1; $i <= 8; $i++) {
            $closes[] = $last - ($i * 0.4);
        }

        $this->seedBars($closes, 'M5');
        $this->seedBars($this->trendCloses(80, rising: false), 'H1');

        // A fifty-ATR stop on a ten-thousand-dollar account sizes below the broker's
        // minimum lot, and a size below the minimum is now declined rather than traded at
        // the minimum. The stop stays wide - it is what keeps the stop and the ladder out
        // of a test about the spread - so the account is the thing that has to be big
        // enough to hold it.
        $free = $this->backtest($this->market(['startingBalance' => 500000.0]));
        $costly = $this->backtest($this->market(['spreadPips' => 4.0, 'startingBalance' => 500000.0]));

        $a = $free->trades[0];
        $b = $costly->trades[0];

        $this->assertSame('sell', $a->direction);
        $this->assertSame('time_exit', $b->closureReason);

        // Entered at bid either way: a sell does not cross the spread going in.
        $this->assertEqualsWithDelta($a->entryPrice, $b->entryPrice, 0.001);

        // Bought back at ask: 4 pips at 0.10 a pip is 0.4 higher, and 4 pips worse.
        $this->assertEqualsWithDelta(0.4, $b->closes[0]['price'] - $a->closes[0]['price'], 0.001);
        $this->assertEqualsWithDelta(-4.0, $b->closes[0]['pips'] - $a->closes[0]['pips'], 0.01);
    }

    /**
     * A sell's stop is a buy at ask, so the ask reaching it is what fires it - a bar whose
     * bid high stays under the stop can still stop the trade out once the spread is added.
     */
    public function test_a_sell_stop_is_triggered_by_the_ask_not_the_bid(): void
    {
        $this->strategy->update(['exit_on_reversal' => false, 'max_holding_bars' => 50, 'tp3_r' => 50]);

        $closes = $this->crossCloses('sell');
        $last = end($closes);

        // Drift up gently; the fixture's bars carry a high a little above each close.
        for ($i = 1; $i <= 12; $i++) {
            $closes[] = $last + ($i * 0.3);
        }

        $this->seedBars($closes, 'M5');
        $this->seedBars($this->trendCloses(80, rising: false), 'H1');

        $free = $this->backtest($this->market());
        $entry = $free->trades[0] ?? $free->unclosed[0];
        $stopDistance = $entry->stopPrice - $entry->entryPrice;

        $this->assertGreaterThan(0, $stopDistance);

        // A spread wide enough that bid never reaches the stop but ask does.
        $wide = $this->backtest($this->market(['spreadPips' => ($stopDistance / 0.10) * 0.9]));
        $trade = $wide->trades[0] ?? null;

        $this->assertNotNull($trade, 'the ask crossing the stop must close the trade');
        $this->assertSame('sl', $trade->closureReason);
    }

    public function test_commission_reduces_net_profit_below_gross(): void
    {
        $this->seedBars($this->winningSeries(), 'M5');
        $this->seedBars($this->trendCloses(80, rising: true), 'H1');

        $report = $this->backtest($this->market(['commissionPerLot' => 7.0]));

        $m = $report->metrics();

        $this->assertGreaterThan(0, $m['costs']);
        $this->assertLessThan($m['gross_pnl'], $m['net_pnl']);
    }

    /**
     * The single biggest source of optimism in a naive ladder backtest. The live system
     * notices a rung when the bar closes and then closes at market, so a fill at the rung
     * is measuring a system nobody built.
     */
    public function test_a_ladder_rung_fills_at_the_bar_close_not_at_the_rung(): void
    {
        $this->seedBars($this->winningSeries(), 'M5');
        $this->seedBars($this->trendCloses(80, rising: true), 'H1');

        $report = $this->backtest();

        $trade = $report->trades[0] ?? $report->unclosed[0];

        $tp1 = collect($trade->closes)->firstWhere('reason', 'tp1');

        $this->assertNotNull($tp1, 'the fixture should reach TP1');

        // The invariant is that the fill is the *close of the bar that reached the rung*,
        // not the rung itself. Asserting "not equal to the rung" would only be testing a
        // coincidence of the fixture's arithmetic - here the two happen to be the same
        // price, and the model would still be wrong if it filled at the rung by rule.
        $reachedOn = null;

        foreach ($this->series('M5') as $bar) {
            if ($bar->open_time->lessThanOrEqualTo($trade->openedAt)) {
                continue;
            }

            if ((float) $bar->high >= $trade->tp1) {
                $reachedOn = $bar;
                break;
            }
        }

        $this->assertNotNull($reachedOn, 'a bar should have reached TP1');
        $this->assertEqualsWithDelta((float) $reachedOn->close, $tp1['price'], 0.0001);
    }

    /**
     * Without ticks the order inside a bar is unknowable. Taking the target would convert
     * every losing bar into a winner.
     */
    public function test_a_bar_spanning_both_stop_and_target_is_treated_as_a_loss(): void
    {
        $closes = $this->crossCloses('buy');

        // One enormous bar after the entry, wide enough to contain the stop and TP3.
        $last = end($closes);
        $closes[] = $last;
        $closes[] = $last;

        $this->seedBars($closes, 'M5');
        $this->seedBars($this->trendCloses(80, rising: true), 'H1');

        // Widen the final bar so its range covers everything either side of entry.
        $bars = $this->series('M5');
        $final = end($bars);
        $final->update(['high' => $final->close + 60, 'low' => $final->close - 60]);

        $report = $this->backtest();

        $this->assertNotEmpty($report->trades);
        $this->assertContains($report->trades[0]->closureReason, ['sl', 'break_even_stop']);
        $this->assertLessThan(0, $report->trades[0]->netPnl);
    }

    /**
     * A trend bar that has not closed yet must not inform an entry on the faster series.
     */
    public function test_trend_bars_from_the_future_are_not_used(): void
    {
        $this->seedBars($this->crossCloses('buy'), 'M5');

        // Trend series ends well after the entry series, so any index-based slice would
        // reach past the decision point.
        $this->seedSeries(
            $this->trendCloses(80, rising: true),
            'H1',
            $this->lastBar->copy()->addDays(5),
            $this->user->id,
            $this->account->id,
            self::SYMBOL,
        );

        $report = $this->backtest();

        // With no trend bars at or before each entry bar, no direction can be confirmed.
        $this->assertSame(0, $report->entriesTaken);
    }

    // =====================================================================
    // FILTERS AND ACCOUNTING
    // =====================================================================

    public function test_declined_setups_are_reported_by_reason(): void
    {
        $this->strategy->update(['adx_threshold' => 99.99]);

        $this->seedBars($this->crossThenRoom()['closes'], 'M5');
        $this->seedBars($this->trendCloses(80, rising: true), 'H1');

        $report = $this->backtest();

        $this->assertSame(0, $report->entriesTaken);
        $this->assertArrayHasKey('adx_below_threshold', $report->skips);
    }

    public function test_the_concurrent_cap_is_respected(): void
    {
        $this->settings->update(['max_concurrent_trades' => 1]);

        $this->seedBars($this->winningSeries(), 'M5');
        $this->seedBars($this->trendCloses(80, rising: true), 'H1');

        $report = $this->backtest();

        // Never more than one position at a time, so no bar can open a second.
        $this->assertLessThanOrEqual(1, $report->entriesTaken);
    }

    /**
     * A position still open when the data runs out has no result. Counting it would inflate
     * whichever side it happens to be sitting on.
     */
    public function test_positions_open_at_the_end_are_excluded_from_the_metrics(): void
    {
        $this->seedBars($this->crossCloses('buy'), 'M5');
        $this->seedBars($this->trendCloses(80, rising: true), 'H1');

        $report = $this->backtest();

        if ($report->unclosed !== []) {
            $this->assertSame(0, $report->metrics()['trades']);
        }

        $this->assertSame(count($report->trades), $report->metrics()['trades']);
    }

    public function test_the_report_serialises_with_its_assumptions(): void
    {
        $this->seedBars($this->winningSeries(), 'M5');
        $this->seedBars($this->trendCloses(80, rising: true), 'H1');

        $data = $this->backtest($this->market(['spreadPips' => 2.5]))->toArray();

        // The assumptions travel with the numbers: a result read a month later without them
        // is not interpretable.
        $this->assertSame(2.5, $data['assumptions']['spread_pips']);
        $this->assertSame(10.0, $data['assumptions']['pip_value_per_lot']);
        $this->assertArrayHasKey('metrics', $data);
        $this->assertArrayHasKey('exits', $data);
    }

    /**
     * No losing trades is too few trades, not an infinite edge - and a number invites
     * belief in a way a blank does not.
     */
    public function test_profit_factor_is_not_reported_as_infinite(): void
    {
        $this->seedBars($this->winningSeries(), 'M5');
        $this->seedBars($this->trendCloses(80, rising: true), 'H1');

        $m = $this->backtest()->metrics();

        $this->assertIsFloat($m['profit_factor']);
        $this->assertLessThan(1000, $m['profit_factor']);
    }

    // =====================================================================
    // THE COMMAND
    // =====================================================================

    public function test_the_command_reports_when_there_are_no_candles(): void
    {
        $this->artisan('backtest '.$this->strategy->id)
            ->expectsOutputToContain('No M5 candles stored')
            ->assertFailed();
    }

    public function test_the_command_runs_and_writes_nothing_to_the_database(): void
    {
        $this->seedBars($this->winningSeries(), 'M5');
        $this->seedBars($this->trendCloses(80, rising: true), 'H1');

        $before = [
            'signals' => Signal::count(),
            'trades' => Trade::count(),
            'commands' => TradeCommand::count(),
        ];

        $this->artisan('backtest '.$this->strategy->id)
            // The header has to say which latency and which lot grid produced the numbers
            // under it, or two runs cannot be compared.
            ->expectsOutputToContain('latency 10.0s (assumed), lots on a 0.01 grid, minimum 0.01')
            ->assertSuccessful();

        $this->artisan('backtest '.$this->strategy->id.' --latency=2.5')
            ->expectsOutputToContain('latency 2.5s (given)')
            ->assertSuccessful();

        // A backtest that left rows behind would poison the analytics it exists to inform.
        $this->assertSame($before['signals'], Signal::count());
        $this->assertSame($before['trades'], Trade::count());
        $this->assertSame($before['commands'], TradeCommand::count());
    }

    // =====================================================================
    // THE BROKER'S LOT GRID
    // =====================================================================

    /**
     * A size the arithmetic produces is not a size the broker holds. 0.1554 lots is traded
     * as 0.15, and simulating the unsnapped figure scores a position that never existed.
     */
    public function test_an_entry_is_sized_on_the_brokers_lot_grid(): void
    {
        // Closed by the clock, so the position lands in `trades` rather than in `unclosed`.
        $this->strategy->update(['max_holding_bars' => 3, 'tp3_r' => 50]);

        ['closes' => $closes] = $this->crossThenRoom(12);

        $this->seedBars($closes, 'M5');
        $this->seedBars($this->trendCloses(80, rising: true), 'H1');

        $report = $this->backtest();

        $this->assertNotEmpty($report->trades, 'fixture should take a trade');

        $lots = $report->trades[0]->lots;

        $this->assertSame($lots, round($lots, 2), 'lots should sit on the 0.01 grid');
        $this->assertEqualsWithDelta(0.0, fmod(round($lots * 100), 1.0), 1e-9);
    }

    /**
     * The defect this pair of assertions exists for.
     *
     * A wide stop on a small account sizes below the broker's minimum lot.
     * `CFXSExecutor::NormalizeVolume` does not refuse that - it raises it to the minimum -
     * so the position traded is larger than the risk setting asked for while the backtest
     * scores the smaller one. Declining is the honest answer, and it is recorded by name.
     */
    public function test_a_setup_below_the_minimum_lot_is_declined_rather_than_traded_at_the_minimum(): void
    {
        // A stop fifty ATRs wide against a 1% risk on ten thousand dollars: a few
        // thousandths of a lot.
        $this->strategy->update(['sl_atr_multiplier' => 50, 'tp3_r' => 50, 'max_holding_bars' => 3]);

        ['closes' => $closes] = $this->crossThenRoom(12);

        $this->seedBars($closes, 'M5');
        $this->seedBars($this->trendCloses(80, rising: true), 'H1');

        $report = $this->backtest();

        $this->assertSame([], $report->trades);
        $this->assertArrayHasKey('below_min_volume', $report->skips);

        // And the same setup on an account big enough for it does trade, so the decline is
        // about the size rather than about the setup.
        $this->assertNotEmpty($this->backtest($this->market(['startingBalance' => 500000.0]))->trades);
    }

    /**
     * A position the broker could not divide is not divided here either.
     *
     * `TradeManager::rungVolume()` refuses a rung whose share is below the broker's minimum,
     * or whose remainder would be, and lets such a position run to its final target whole.
     * A backtest that takes the rung anyway scores a ladder that would have failed at every
     * step - which is the one outcome a small account is most likely to see.
     */
    public function test_a_position_too_small_to_divide_takes_no_rungs(): void
    {
        $this->strategy->update(['max_holding_bars' => 30, 'tp3_r' => 50]);

        $this->seedBars($this->winningSeries(), 'M5');
        $this->seedBars($this->trendCloses(80, rising: true), 'H1');

        // A minimum equal to whatever the entry sizes at: any share of it is below the
        // minimum, and so is any remainder.
        $whole = $this->backtest($this->market(['volumeMin' => 0.01, 'volumeStep' => 0.01]));
        $entry = $whole->trades[0]->lots;

        $undividable = $this->backtest($this->market([
            'volumeStep' => $entry,
            'volumeMin' => $entry,
        ]));

        // filledRungs() lists every close, the final one included, so the rungs are what is
        // being asserted about - not the length of the list.
        $this->assertNotContains('tp1', $undividable->trades[0]->filledRungs());
        $this->assertNotContains('tp2', $undividable->trades[0]->filledRungs());

        // And the same fixture on a divisible grid does take them, so the refusal is about
        // the size rather than about the ladder.
        $this->assertContains('tp1', $whole->trades[0]->filledRungs());
    }

    // =====================================================================
    // LATENCY
    // =====================================================================

    /**
     * Nothing here executes at the price that triggered it.
     *
     * A bar closes, the EA pushes it on its next timer tick, the dashboard queues a
     * command, and the EA claims it on the tick after that. The backtester used to fill at
     * the next bar's open, which is the price at the moment of the close - the one price
     * the system certainly did not get. With a latency of a fifth of a bar, the fill takes
     * a fifth of that bar's adverse excursion.
     */
    public function test_latency_fills_an_entry_into_the_bar_rather_than_at_its_open(): void
    {
        $this->strategy->update(['max_holding_bars' => 3, 'tp3_r' => 50]);

        ['closes' => $closes] = $this->crossThenRoom(12);

        $this->seedBars($closes, 'M5');
        $this->seedBars($this->trendCloses(80, rising: true), 'H1');

        // 60 seconds of a 300-second bar.
        $instant = $this->backtest($this->market());
        $delayed = $this->backtest($this->market(['latencySeconds' => 60.0]));

        $a = $instant->trades[0];
        $b = $delayed->trades[0];

        $fillBar = $this->barAt($a->openedAt);

        $this->assertSame('buy', $a->direction);
        $this->assertEqualsWithDelta((float) $fillBar->open, $a->entryPrice, 0.001, 'no latency fills at the open');

        // A buy fills worse higher, so it pays a fifth of the distance from the open to
        // the bar's high.
        $expected = (float) $fillBar->open + (0.2 * ((float) $fillBar->high - (float) $fillBar->open));

        $this->assertEqualsWithDelta($expected, $b->entryPrice, 0.001);
        $this->assertGreaterThan($a->entryPrice, $b->entryPrice);
    }

    /**
     * A sell is delayed the other way: it fills lower, which is also worse.
     */
    public function test_latency_moves_a_sell_entry_down_rather_than_up(): void
    {
        $this->strategy->update(['max_holding_bars' => 3, 'tp3_r' => 50]);

        $closes = $this->crossCloses('sell');
        $last = end($closes);

        for ($i = 1; $i <= 8; $i++) {
            $closes[] = $last - ($i * 0.4);
        }

        $this->seedBars($closes, 'M5');
        $this->seedBars($this->trendCloses(80, rising: false), 'H1');

        $instant = $this->backtest($this->market());
        $delayed = $this->backtest($this->market(['latencySeconds' => 60.0]));

        $a = $instant->trades[0];
        $b = $delayed->trades[0];

        $fillBar = $this->barAt($a->openedAt);
        $expected = (float) $fillBar->open + (0.2 * ((float) $fillBar->low - (float) $fillBar->open));

        $this->assertSame('sell', $a->direction);
        $this->assertEqualsWithDelta($expected, $b->entryPrice, 0.001);
        $this->assertLessThan($a->entryPrice, $b->entryPrice);
    }

    /**
     * Exits decided on a bar close wait in the same queue an entry waits in, so a rung, a
     * reversal exit and a time exit all fill inside the *next* bar. The stop and the final
     * target do not: those sit on the order at the broker and need nothing sent.
     */
    public function test_latency_moves_a_market_exit_against_the_position(): void
    {
        $this->strategy->update(['max_holding_bars' => 3, 'tp3_r' => 50, 'exit_on_reversal' => false]);

        ['closes' => $closes] = $this->crossThenRoom(12);

        $this->seedBars($closes, 'M5');
        $this->seedBars($this->trendCloses(80, rising: true), 'H1');

        $instant = $this->backtest($this->market());
        $delayed = $this->backtest($this->market(['latencySeconds' => 60.0]));

        $a = $instant->trades[0];
        $b = $delayed->trades[0];

        $this->assertSame('time_exit', $a->closureReason);
        $this->assertSame('time_exit', $b->closureReason);

        // A buy is closed by selling, so a delayed exit sells lower - and its pip result
        // is worse for it, not merely different.
        $this->assertLessThan($a->closes[0]['price'], $b->closes[0]['price']);
        $this->assertLessThan($a->closes[0]['pips'], $b->closes[0]['pips']);
    }

    /**
     * Latency is a cost or it is nothing. A bar that gapped the trade's way would otherwise
     * hand the drift back as profit, which would make a slow queue an edge.
     */
    public function test_latency_never_pays_out(): void
    {
        $this->strategy->update(['max_holding_bars' => 3, 'tp3_r' => 50, 'exit_on_reversal' => false]);

        ['closes' => $closes] = $this->crossThenRoom(12);

        $this->seedBars($closes, 'M5');
        $this->seedBars($this->trendCloses(80, rising: true), 'H1');

        $instant = $this->backtest($this->market())->metrics();
        $delayed = $this->backtest($this->market(['latencySeconds' => 60.0]))->metrics();

        $this->assertLessThanOrEqual($instant['net_pnl'], $delayed['net_pnl']);
    }

    /**
     * The report has to say which latency produced it, or two runs cannot be compared.
     */
    public function test_the_report_records_the_latency_and_the_lot_grid(): void
    {
        ['closes' => $closes] = $this->crossThenRoom(12);

        $this->seedBars($closes, 'M5');
        $this->seedBars($this->trendCloses(80, rising: true), 'H1');

        $assumptions = $this->backtest($this->market(['latencySeconds' => 7.5]))->toArray()['assumptions'];

        $this->assertSame(7.5, $assumptions['latency_seconds']);
        $this->assertSame(0.01, $assumptions['volume_step']);
        $this->assertSame(0.01, $assumptions['volume_min']);
    }

    // =====================================================================
    // THE LATENCY ASSUMPTION ITSELF
    // =====================================================================

    /**
     * The figure is not guessed where there is a queue to measure.
     *
     * `trade_commands` records when a command was created and when an executor claimed it,
     * so the wait is this deployment's own, in its own conditions. What the measurement
     * cannot see is the leg before it - the bar closing and waiting to be pushed - which
     * has no timestamp of its own, so one nominal poll interval is added for it.
     */
    public function test_the_latency_assumption_is_measured_from_the_queue(): void
    {
        $heartbeat = $this->heartbeatFor();

        // Twelve commands, each claimed four seconds after it was queued.
        for ($i = 1; $i <= 12; $i++) {
            $created = now()->subMinutes($i);

            $this->claimedCommand("latency:{$i}", $created, 4);
        }

        $this->assertSame(
            4.0 + MarketAssumptions::PUSH_SECONDS,
            MarketAssumptions::measuredLatency($heartbeat),
        );

        $this->assertSame(
            4.0 + MarketAssumptions::PUSH_SECONDS,
            MarketAssumptions::fromHeartbeat($heartbeat)->latencySeconds,
        );
    }

    /**
     * A handful of commands is one afternoon, not a distribution. The assumption falls back
     * rather than believing them.
     */
    public function test_too_few_commands_fall_back_to_the_assumed_latency(): void
    {
        $heartbeat = $this->heartbeatFor();

        for ($i = 1; $i <= 3; $i++) {
            $created = now()->subMinutes($i);

            $this->claimedCommand("sparse:{$i}", $created, 30);
        }

        $this->assertNull(MarketAssumptions::measuredLatency($heartbeat));
        $this->assertSame(
            MarketAssumptions::DEFAULT_LATENCY_SECONDS,
            MarketAssumptions::fromHeartbeat($heartbeat)->latencySeconds,
        );
    }

    /**
     * And a figure given on the command line beats both, because a sweep over latency is
     * the point of having the number at all.
     */
    public function test_a_given_latency_overrides_what_was_measured(): void
    {
        $this->assertSame(
            2.5,
            MarketAssumptions::fromHeartbeat($this->heartbeatFor(), ['latencySeconds' => 2.5])->latencySeconds,
        );
    }

    /**
     * A command queued at $created and claimed $waited seconds later.
     *
     * `created_at` is not fillable, so it is written after the insert rather than through
     * it - mass-assigning it silently leaves the row stamped `now()`, which would make the
     * wait negative and this fixture measure nothing.
     */
    private function claimedCommand(string $key, Carbon $created, int $waited): void
    {
        $command = TradeCommand::create([
            'user_id' => $this->user->id,
            'broker_account_id' => $this->account->id,
            'type' => 'open',
            'payload' => ['symbol' => self::SYMBOL, 'volume' => 0.1],
            'status' => 'done',
            'idempotency_key' => $key,
            'claimed_at' => $created->copy()->addSeconds($waited),
        ]);

        TradeCommand::whereKey($command->id)->update(['created_at' => $created]);
    }

    private function heartbeatFor(): BotHeartbeat
    {
        return BotHeartbeat::create([
            'user_id' => $this->user->id,
            'broker_account_id' => $this->account->id,
            'source' => 'mql5_ea',
            'algo_trading_enabled' => true,
            'broker_connected' => true,
            'resolved_symbol' => self::SYMBOL,
            'pip_size' => 0.10,
            'pip_value_per_lot' => 10.0,
            'volume_min' => 0.01,
            'volume_step' => 0.01,
            'balance' => 10000.00,
            'equity' => 10000.00,
            'last_seen_at' => now(),
        ]);
    }
}
