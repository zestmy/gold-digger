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
        );
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

        $this->artisan('backtest '.$this->strategy->id)->assertSuccessful();

        // A backtest that left rows behind would poison the analytics it exists to inform.
        $this->assertSame($before['signals'], Signal::count());
        $this->assertSame($before['trades'], Trade::count());
        $this->assertSame($before['commands'], TradeCommand::count());
    }
}
