<?php

namespace Tests\Feature\Phase4;

use App\Models\BotHeartbeat;
use App\Models\BotSettings;
use App\Models\BrokerAccount;
use App\Models\Candle;
use App\Models\Strategy;
use App\Models\Trade;
use App\Models\TradeCommand;
use App\Models\TradePartial;
use App\Models\User;
use App\Services\Backtest\Backtester;
use App\Services\Backtest\MarketAssumptions;
use App\Services\Backtest\ParameterGrid;
use App\Services\Strategy\TradeManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\MakesPriceSeries;
use Tests\TestCase;

/**
 * Trailing stops and a break-even that breaks even.
 *
 * The property under everything here is that a stop only ever moves toward profit. Loosening
 * one would widen a risk that was decided when the position opened, and no rule in this system
 * is allowed to do that - so most of these tests are about the move that must *not* happen.
 *
 * The live manager and the backtester are tested against the same expectations deliberately. A
 * backtest of a rule the live system implements differently measures nothing.
 */
class TrailingStopTest extends TestCase
{
    use MakesPriceSeries;
    use RefreshDatabase;

    private User $user;

    private BrokerAccount $account;

    private Strategy $strategy;

    private Carbon $lastBar;

    private const SYMBOL = 'XAUUSDm';

    private const ENTRY = 2000.00;

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

        BotSettings::where('user_id', $this->user->id)->update([
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
            'trail_trigger_pips' => null,
            'trail_distance_pips' => null,
            'breakeven_offset_pips' => 0,
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

    /**
     * A position, and bars that ran a given distance in its favour then pulled back.
     */
    private function openTrade(array $overrides = []): Trade
    {
        return Trade::create(array_merge([
            'user_id' => $this->user->id,
            'strategy_id' => $this->strategy->id,
            'broker_account_id' => $this->account->id,
            'mt5_ticket' => 990100,
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
            'opened_at' => $this->lastBar->copy()->subDay(),
        ], $overrides));
    }

    /**
     * Flat bars, then one that reaches $peak, then a pullback.
     */
    private function seedRunTo(float $peak, float $settle): void
    {
        $closes = array_fill(0, 60, self::ENTRY);
        $closes[] = $peak;
        $closes[] = $settle;

        $this->seedSeries($closes, 'M5', $this->lastBar, $this->user->id, $this->account->id, self::SYMBOL);
    }

    private function manage(): array
    {
        return app(TradeManager::class)->manage($this->strategy->fresh(), $this->account->id);
    }

    private function modifies(): array
    {
        return TradeCommand::where('type', 'modify')->get()
            ->map(fn (TradeCommand $c) => ['reason' => $c->payload['reason'], 'sl' => (float) $c->payload['sl_price']])
            ->all();
    }

    // =====================================================================
    // IT IS OFF UNTIL ASKED FOR
    // =====================================================================

    /**
     * A setting that changes P&L must not arrive switched on.
     */
    public function test_trailing_is_off_by_default(): void
    {
        $this->openTrade();
        $this->seedRunTo(self::ENTRY + 20, self::ENTRY + 18);

        $this->manage();

        $this->assertSame([], array_filter($this->modifies(), fn ($m) => $m['reason'] === 'trail'));
    }

    public function test_a_trigger_without_a_distance_does_not_trail(): void
    {
        $this->strategy->update(['trail_trigger_pips' => 50]);

        $this->openTrade();
        $this->seedRunTo(self::ENTRY + 20, self::ENTRY + 18);

        $this->manage();

        $this->assertSame([], array_filter($this->modifies(), fn ($m) => $m['reason'] === 'trail'));
    }

    // =====================================================================
    // THE TRAIL
    // =====================================================================

    /**
     * Trigger 50 pips (5.00), distance 20 pips (2.00). A run to +20.00 is 200 pips of profit,
     * so the stop should follow to 20.00 - 2.00 above entry.
     */
    public function test_a_run_past_the_trigger_moves_the_stop_behind_the_best_price(): void
    {
        $this->strategy->update(['trail_trigger_pips' => 50, 'trail_distance_pips' => 20]);

        $this->openTrade();
        $this->seedRunTo(self::ENTRY + 20, self::ENTRY + 18);

        $this->manage();

        $trail = collect($this->modifies())->firstWhere('reason', 'trail');

        $this->assertNotNull($trail);
        // Best high is the peak bar's high, which the fixture sets one point above its close.
        $this->assertEqualsWithDelta(self::ENTRY + 21.0 - 2.0, $trail['sl'], 0.01);
    }

    /**
     * The stop follows the best price reached, not the latest close. Following the close would
     * loosen it on every pullback, which is a drifting stop rather than a trailing one.
     */
    public function test_the_trail_follows_the_high_water_mark_not_the_last_close(): void
    {
        $this->strategy->update(['trail_trigger_pips' => 50, 'trail_distance_pips' => 20]);

        $this->openTrade();
        // Ran to +30, settled back to +5. A close-following stop would sit near 2000.30.
        $this->seedRunTo(self::ENTRY + 30, self::ENTRY + 5);

        $this->manage();

        $trail = collect($this->modifies())->firstWhere('reason', 'trail');

        $this->assertGreaterThan(self::ENTRY + 25, $trail['sl']);
    }

    public function test_a_run_short_of_the_trigger_does_not_move_the_stop(): void
    {
        $this->strategy->update(['trail_trigger_pips' => 300, 'trail_distance_pips' => 20]);

        $this->openTrade();
        $this->seedRunTo(self::ENTRY + 5, self::ENTRY + 4);

        $this->manage();

        $this->assertSame([], array_filter($this->modifies(), fn ($m) => $m['reason'] === 'trail'));
    }

    /**
     * The property everything else rests on. A trail that would sit below the current stop is
     * a widening of risk the position never agreed to.
     */
    public function test_the_stop_is_never_loosened(): void
    {
        $this->strategy->update(['trail_trigger_pips' => 50, 'trail_distance_pips' => 20]);

        // The run peaks at a high of +21.00, so the trail would compute +19.00. The stop is
        // already tighter than that - as it would be after an earlier, higher peak - and
        // moving it to +19.00 would be a loosening.
        $this->openTrade(['sl_price' => self::ENTRY + 20.00]);
        $this->seedRunTo(self::ENTRY + 20, self::ENTRY + 18);

        $this->manage();

        $this->assertSame([], array_filter($this->modifies(), fn ($m) => $m['reason'] === 'trail'));
    }

    /**
     * The manager runs on every bar. Without a level-keyed idempotency key this would be a
     * command per bar; with a key on the trade alone, only the first move would ever happen.
     */
    public function test_the_same_trail_level_is_queued_once(): void
    {
        $this->strategy->update(['trail_trigger_pips' => 50, 'trail_distance_pips' => 20]);

        $this->openTrade();
        $this->seedRunTo(self::ENTRY + 20, self::ENTRY + 18);

        $this->manage();
        $this->manage();
        $this->manage();

        $this->assertCount(1, array_filter($this->modifies(), fn ($m) => $m['reason'] === 'trail'));
    }

    /**
     * A sell trails downward, and its stop must never move up.
     */
    public function test_a_sell_trails_the_other_way(): void
    {
        $this->strategy->update(['trail_trigger_pips' => 50, 'trail_distance_pips' => 20]);

        $this->openTrade([
            'direction' => 'sell',
            'sl_price' => self::ENTRY + 5.00,
            'tp1_price' => self::ENTRY - 3.00,
            'tp2_price' => self::ENTRY - 10.00,
            'tp3_price' => self::ENTRY - 30.00,
        ]);

        $this->seedRunTo(self::ENTRY - 20, self::ENTRY - 18);

        $this->manage();

        $trail = collect($this->modifies())->firstWhere('reason', 'trail');

        $this->assertNotNull($trail);
        $this->assertLessThan(self::ENTRY, $trail['sl']);
    }

    // =====================================================================
    // BREAK-EVEN THAT BREAKS EVEN
    // =====================================================================

    private function fillTp1(Trade $trade): void
    {
        TradePartial::create([
            'trade_id' => $trade->id,
            'mt5_deal_ticket' => 5001,
            'closed_lot_size' => 0.50,
            'close_price' => self::ENTRY + 3.00,
            'close_reason' => 'tp1',
            'pips_profit' => 30,
            'gross_money_profit' => 150,
            'commission_money' => 0,
            'swap_money' => 0,
            'net_money_profit' => 150,
            'closed_at' => now(),
        ]);

        $trade->update(['remaining_lot_size' => 0.50, 'status' => 'partially_closed']);
    }

    /**
     * Moving the stop to exactly the entry leaves the trade losing the spread and commission
     * it paid to get there. The offset is what makes the phrase true.
     */
    public function test_the_break_even_stop_clears_the_cost_of_the_round_trip(): void
    {
        $this->strategy->update(['breakeven_offset_pips' => 8]);

        $trade = $this->openTrade();
        $this->fillTp1($trade);
        $this->seedRunTo(self::ENTRY + 4, self::ENTRY + 4);

        $this->manage();

        $breakEven = collect($this->modifies())->firstWhere('reason', 'break_even');

        $this->assertNotNull($breakEven);
        // Eight pips on gold is 0.80 of price.
        $this->assertEqualsWithDelta(self::ENTRY + 0.80, $breakEven['sl'], 0.001);
    }

    public function test_a_zero_offset_preserves_the_previous_behaviour(): void
    {
        $this->strategy->update(['breakeven_offset_pips' => 0]);

        $trade = $this->openTrade();
        $this->fillTp1($trade);
        $this->seedRunTo(self::ENTRY + 4, self::ENTRY + 4);

        $this->manage();

        $breakEven = collect($this->modifies())->firstWhere('reason', 'break_even');

        $this->assertEqualsWithDelta(self::ENTRY, $breakEven['sl'], 0.001);
    }

    public function test_a_stop_already_past_break_even_is_not_moved_back(): void
    {
        $this->strategy->update(['breakeven_offset_pips' => 8]);

        $trade = $this->openTrade(['sl_price' => self::ENTRY + 5.00]);
        $this->fillTp1($trade);
        $this->seedRunTo(self::ENTRY + 6, self::ENTRY + 6);

        $this->manage();

        $this->assertSame([], array_filter($this->modifies(), fn ($m) => $m['reason'] === 'break_even'));
    }

    // =====================================================================
    // THE BACKTESTER AGREES
    // =====================================================================

    /**
     * A backtest of a rule the live system implements differently measures nothing. This is
     * the check that the two moved together.
     */
    public function test_the_backtester_trails_and_reports_it_as_a_distinct_exit(): void
    {
        $this->strategy->update([
            'trail_trigger_pips' => 40,
            'trail_distance_pips' => 25,
            'tp3_pips' => 900,   // far enough away that the trail is what ends the trade
        ]);

        // A crossover, a strong run, then a collapse that takes out the trailed stop.
        $closes = $this->crossCloses('buy');
        $last = end($closes);

        for ($i = 1; $i <= 30; $i++) {
            $closes[] = $last + ($i * 3.0);
        }

        $peak = end($closes);

        for ($i = 1; $i <= 30; $i++) {
            $closes[] = $peak - ($i * 4.0);
        }

        $this->seedSeries($closes, 'M5', $this->lastBar, $this->user->id, $this->account->id, self::SYMBOL);
        $this->seedSeries($this->trendCloses(80, rising: true), 'H1', $this->lastBar, $this->user->id, $this->account->id, self::SYMBOL);

        $report = app(Backtester::class)->run(
            $this->strategy->fresh(),
            Candle::where('timeframe', 'M5')->orderBy('open_time')->get()->all(),
            Candle::where('timeframe', 'H1')->orderBy('open_time')->get()->all(),
            new MarketAssumptions(0.10, 10.0, 0.01, 0.0, 0.0, 0.0, 10000.0),
            BotSettings::where('user_id', $this->user->id)->first(),
        );

        $this->assertNotEmpty($report->trades, 'the fixture should take a trade');

        $trade = $report->trades[0];

        $this->assertTrue($trade->trailing, 'the trail should have engaged on the run up');
        $this->assertSame('trailing_stop', $trade->closureReason);
        // The whole point: the position kept some of the run instead of returning to entry.
        $this->assertGreaterThan(0, $trade->netPnl);
    }

    /**
     * Same series, trailing off. The comparison is the reason the backtester exists - without
     * it, "trailing helps" is an opinion.
     */
    public function test_trailing_changes_the_outcome_measurably(): void
    {
        $closes = $this->crossCloses('buy');
        $last = end($closes);

        for ($i = 1; $i <= 30; $i++) {
            $closes[] = $last + ($i * 3.0);
        }

        $peak = end($closes);

        for ($i = 1; $i <= 30; $i++) {
            $closes[] = $peak - ($i * 4.0);
        }

        $this->seedSeries($closes, 'M5', $this->lastBar, $this->user->id, $this->account->id, self::SYMBOL);
        $this->seedSeries($this->trendCloses(80, rising: true), 'H1', $this->lastBar, $this->user->id, $this->account->id, self::SYMBOL);

        $entry = Candle::where('timeframe', 'M5')->orderBy('open_time')->get()->all();
        $trend = Candle::where('timeframe', 'H1')->orderBy('open_time')->get()->all();
        $market = new MarketAssumptions(0.10, 10.0, 0.01, 0.0, 0.0, 0.0, 10000.0);
        $settings = BotSettings::where('user_id', $this->user->id)->first();

        $this->strategy->update(['tp3_pips' => 900, 'trail_trigger_pips' => null, 'trail_distance_pips' => null]);
        $without = app(Backtester::class)->run($this->strategy->fresh(), $entry, $trend, $market, $settings);

        $this->strategy->update(['trail_trigger_pips' => 40, 'trail_distance_pips' => 25]);
        $with = app(Backtester::class)->run($this->strategy->fresh(), $entry, $trend, $market, $settings);

        $this->assertNotEqualsWithDelta(
            $without->metrics()['net_pnl'],
            $with->metrics()['net_pnl'],
            0.01,
            'trailing should change the result on a series that runs then collapses',
        );
    }

    public function test_the_new_settings_can_be_swept(): void
    {
        $grid = new ParameterGrid(['trail_distance_pips=20,30,40']);

        $this->assertCount(3, $grid->combinations());
    }
}
