<?php

namespace Tests\Feature\Signals;

use App\Models\BotHeartbeat;
use App\Models\BotSettings;
use App\Models\BrokerAccount;
use App\Models\Strategy;
use App\Models\Trade;
use App\Models\TradeCommand;
use App\Models\TradePartial;
use App\Models\User;
use App\Services\Strategy\TradeManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\MakesPriceSeries;
use Tests\TestCase;

/**
 * Trade management.
 *
 * The take-profit ladder, the reversal and time exits, and the break-even stop move - the
 * `strategies` columns that were stored and unread until now.
 *
 * These tests care most about what must *not* happen twice. A duplicated entry costs one
 * unwanted position; a duplicated close on a laddered trade closes more of it than the
 * strategy asked for, and the position that was supposed to run to the final target is
 * simply gone.
 */
class TradeManagementTest extends TestCase
{
    use MakesPriceSeries;
    use RefreshDatabase;

    private User $user;

    private BrokerAccount $account;

    private Strategy $strategy;

    private Carbon $lastBar;

    private const SYMBOL = 'XAUUSDm';

    /** Entry, and the ladder built from it. Chosen so every level is far from the others. */
    private const ENTRY = 2000.00;

    private const TP1 = 2003.00;

    private const TP2 = 2010.00;

    private const TP3 = 2020.00;

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

        BotSettings::where('user_id', $this->user->id)->update(['is_active' => true]);

        $this->strategy = Strategy::where('user_id', $this->user->id)->firstOrFail();
        $this->strategy->update([
            'is_active' => true,
            'tp1_close_pct' => 50.00,
            'tp2_close_pct' => 30.00,
            // Each exit rule has its own test; both off by default so a ladder test is not
            // silently answering a different question.
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

    // =====================================================================
    // THE LADDER
    // =====================================================================

    public function test_touching_tp1_queues_a_partial_close_for_the_configured_share(): void
    {
        $trade = $this->openTrade(lots: 1.00);
        $this->seedBarsReaching(self::TP1);

        $this->manage();

        $command = TradeCommand::where('type', 'close')->firstOrFail();

        $this->assertSame('tp1', $command->payload['reason']);
        $this->assertEqualsWithDelta(0.50, (float) $command->payload['volume'], 1e-9);
        $this->assertSame($trade->mt5_ticket, $command->payload['ticket']);
        $this->assertSame($trade->id, $command->trade_id);
    }

    /**
     * 50 / 30 / 20 sums to 100, so each is a share of what was opened. Reading TP2's 30% as
     * "30% of what is left" would close 0.15 of a 1.00-lot trade, and the ladder would never
     * finish.
     */
    public function test_ladder_percentages_are_shares_of_the_initial_position(): void
    {
        $this->openTrade(lots: 1.00);
        $this->seedBarsReaching(self::TP2);

        $this->manage();

        $tp2 = TradeCommand::where('type', 'close')
            ->get()
            ->first(fn (TradeCommand $c) => $c->payload['reason'] === 'tp2');

        $this->assertNotNull($tp2);
        $this->assertEqualsWithDelta(0.30, (float) $tp2->payload['volume'], 1e-9);
    }

    /**
     * A bar that ran through both levels should take both rungs. This is also the
     * bot-was-down case: the rungs are measured across every bar since entry.
     */
    public function test_a_bar_through_both_levels_takes_both_rungs(): void
    {
        $this->openTrade(lots: 1.00);
        $this->seedBarsReaching(self::TP2);

        $this->manage();

        $reasons = TradeCommand::where('type', 'close')->get()
            ->map(fn (TradeCommand $c) => $c->payload['reason'])
            ->sort()
            ->values()
            ->all();

        $this->assertSame(['tp1', 'tp2'], $reasons);
    }

    /**
     * The most expensive duplicate in this system. Re-running on every bar must collapse
     * onto the command already queued.
     */
    public function test_re_running_does_not_queue_a_second_close_for_the_same_rung(): void
    {
        $this->openTrade(lots: 1.00);
        $this->seedBarsReaching(self::TP1);

        $this->manage();
        $this->manage();
        $this->manage();

        $this->assertSame(1, TradeCommand::where('type', 'close')->count());
    }

    public function test_price_short_of_tp1_queues_nothing(): void
    {
        $this->openTrade(lots: 1.00);
        $this->seedBarsReaching(self::ENTRY + 1.00);

        $this->manage();

        $this->assertSame(0, TradeCommand::count());
    }

    /**
     * A sell's ladder sits below entry, so the bars' lows are what reach it.
     */
    public function test_a_sell_ladders_downwards(): void
    {
        $this->openTrade(lots: 1.00, direction: 'sell');
        $this->seedBarsReaching(self::ENTRY - 3.00);

        $command = TradeCommand::where('type', 'close')->first();
        $this->assertNull($command);

        $this->manage();

        $this->assertSame('tp1', TradeCommand::where('type', 'close')->firstOrFail()->payload['reason']);
    }

    /**
     * The order carries the final rung, so the broker closes the remainder itself. Queueing
     * a partial at that same price would close part of a position about to close whole.
     */
    public function test_the_final_target_is_left_to_the_broker(): void
    {
        $this->openTrade(lots: 1.00);
        $this->seedBarsReaching(self::TP3 + 5.00);

        $this->manage();

        $reasons = TradeCommand::where('type', 'close')->get()
            ->map(fn (TradeCommand $c) => $c->payload['reason'])
            ->all();

        $this->assertNotContains('tp3', $reasons);
    }

    /**
     * With no TP3 the order carries TP2, so TP2 is the broker's target and must not be
     * laddered either.
     */
    public function test_tp2_is_not_laddered_when_it_is_the_final_target(): void
    {
        $trade = $this->openTrade(lots: 1.00);
        $trade->update(['tp3_price' => null]);

        $this->seedBarsReaching(self::TP2 + 1.00);
        $this->manage();

        $reasons = TradeCommand::where('type', 'close')->get()
            ->map(fn (TradeCommand $c) => $c->payload['reason'])
            ->all();

        $this->assertSame(['tp1'], $reasons);
    }

    // =====================================================================
    // POSITIONS TOO SMALL TO DIVIDE
    // =====================================================================

    /**
     * Half of the broker's minimum lot is not a tradeable volume. The executor snaps it to
     * zero and the close fails, so without this the smallest possible position would
     * generate a failing command at every rung of every trade.
     */
    public function test_a_position_at_the_brokers_minimum_is_not_laddered(): void
    {
        $this->openTrade(lots: 0.01);
        $this->seedBarsReaching(self::TP1);

        $this->manage();

        $this->assertSame(0, TradeCommand::count());
    }

    /**
     * A rung that is itself legal but would leave an illegal remainder is refused too - the
     * broker will not let a position sit below its minimum volume.
     */
    public function test_a_rung_that_would_leave_an_illegal_remainder_is_refused(): void
    {
        BotHeartbeat::where('user_id', $this->user->id)->update(['volume_min' => 0.10]);

        // 50% of 0.15 is 0.075, below the 0.10 minimum on both sides.
        $this->openTrade(lots: 0.15);
        $this->seedBarsReaching(self::TP1);

        $this->manage();

        $this->assertSame(0, TradeCommand::count());
    }

    public function test_never_asks_to_close_more_than_remains(): void
    {
        $trade = $this->openTrade(lots: 1.00);
        $trade->update(['remaining_lot_size' => 0.40, 'status' => 'partially_closed']);

        $this->seedBarsReaching(self::TP1);
        $this->manage();

        // The tp1 rung is 0.50 of the initial lot but only 0.40 is still open.
        $tp1 = TradeCommand::where('type', 'close')->get()
            ->first(fn (TradeCommand $c) => $c->payload['reason'] === 'tp1');

        $this->assertNull($tp1);
    }

    // =====================================================================
    // WHOLE-POSITION EXITS
    // =====================================================================

    public function test_a_reversal_closes_the_whole_remaining_position(): void
    {
        $this->strategy->update(['exit_on_reversal' => true]);

        $trade = $this->openTrade(lots: 1.00, direction: 'buy');

        // A bearish crossover on the entry series, against a long position.
        $this->seedBars($this->crossCloses('sell'));

        $this->manage();

        $command = TradeCommand::where('type', 'close')->firstOrFail();

        $this->assertSame('reversal_exit', $command->payload['reason']);
        $this->assertEqualsWithDelta((float) $trade->remaining_lot_size, (float) $command->payload['volume'], 1e-9);
    }

    public function test_a_reversal_in_the_position_s_own_direction_is_not_an_exit(): void
    {
        $this->strategy->update(['exit_on_reversal' => true]);

        $this->openTrade(lots: 1.00, direction: 'sell');
        $this->seedBars($this->crossCloses('sell'));

        $this->manage();

        $this->assertSame(0, TradeCommand::where('type', 'close')->count());
    }

    public function test_the_reversal_exit_is_off_when_the_strategy_says_so(): void
    {
        $this->strategy->update(['exit_on_reversal' => false]);

        $this->openTrade(lots: 1.00, direction: 'buy');
        $this->seedBars($this->crossCloses('sell'));

        $this->manage();

        // Asserted on the reason rather than on the command count: this fixture's rally
        // runs through the ladder on its way to the crossover, so ordinary rungs are
        // queued and should be. What must be absent is the reversal exit itself.
        $reasons = TradeCommand::where('type', 'close')->get()
            ->map(fn (TradeCommand $c) => $c->payload['reason'])
            ->all();

        $this->assertNotContains('reversal_exit', $reasons);
    }

    public function test_exceeding_the_holding_limit_closes_the_position(): void
    {
        $this->strategy->update(['max_holding_bars' => 10]);

        $this->openTrade(lots: 1.00);
        // 20 flat bars since entry, comfortably past the limit and reaching no rung.
        $this->seedBars(array_fill(0, 20, self::ENTRY));

        $this->manage();

        $this->assertSame('time_exit', TradeCommand::where('type', 'close')->firstOrFail()->payload['reason']);
    }

    public function test_a_position_inside_the_holding_limit_is_left_alone(): void
    {
        $this->strategy->update(['max_holding_bars' => 50]);

        $this->openTrade(lots: 1.00);
        $this->seedBars(array_fill(0, 20, self::ENTRY));

        $this->manage();

        $this->assertSame(0, TradeCommand::count());
    }

    /**
     * An exit takes the whole position, so pairing it with a partial would queue two
     * commands where one does the job - and the partial's fill would move the exit's.
     */
    public function test_an_exit_supersedes_the_ladder_on_the_same_bar(): void
    {
        $this->strategy->update(['max_holding_bars' => 5]);

        $this->openTrade(lots: 1.00);
        $this->seedBarsReaching(self::TP1);

        $this->manage();

        $reasons = TradeCommand::where('type', 'close')->get()
            ->map(fn (TradeCommand $c) => $c->payload['reason'])
            ->all();

        $this->assertSame(['time_exit'], $reasons);
    }

    // =====================================================================
    // BREAK-EVEN
    // =====================================================================

    /**
     * Queued is not filled. Moving the stop to entry while the TP1 partial is still in
     * flight would put the *whole* position on a break-even stop, which is a different
     * trade from the one the strategy described.
     */
    public function test_break_even_waits_for_the_first_rung_to_actually_fill(): void
    {
        $this->openTrade(lots: 1.00);
        $this->seedBarsReaching(self::TP1);

        $this->manage();

        $this->assertSame(0, TradeCommand::where('type', 'modify')->count());
    }

    public function test_a_filled_first_rung_moves_the_stop_to_entry(): void
    {
        $trade = $this->openTrade(lots: 1.00);
        $this->seedBarsReaching(self::TP1);

        $this->fillRung($trade, 'tp1', 0.50);
        $this->manage();

        $command = TradeCommand::where('type', 'modify')->firstOrFail();

        $this->assertEqualsWithDelta(self::ENTRY, (float) $command->payload['sl_price'], 1e-9);
        $this->assertSame('break_even', $command->payload['reason']);
    }

    public function test_break_even_is_queued_only_once(): void
    {
        $trade = $this->openTrade(lots: 1.00);
        $this->seedBarsReaching(self::TP1);

        $this->fillRung($trade, 'tp1', 0.50);

        $this->manage();
        $this->manage();

        $this->assertSame(1, TradeCommand::where('type', 'modify')->count());
    }

    public function test_a_stop_already_at_entry_is_not_moved_again(): void
    {
        $trade = $this->openTrade(lots: 1.00);
        $this->seedBarsReaching(self::TP1);

        $this->fillRung($trade, 'tp1', 0.50);
        $trade->update(['sl_price' => self::ENTRY]);

        $this->manage();

        $this->assertSame(0, TradeCommand::where('type', 'modify')->count());
    }

    /**
     * The modify command must carry an absolute stop level: the wire's sl_price column is
     * what the EA reads, and pips would be meaningless for a level rather than a distance.
     */
    public function test_the_modify_wire_line_carries_the_absolute_stop(): void
    {
        $trade = $this->openTrade(lots: 1.00);
        $this->seedBarsReaching(self::TP1);
        $this->fillRung($trade, 'tp1', 0.50);

        $this->manage();

        $command = TradeCommand::where('type', 'modify')->firstOrFail();
        $columns = array_combine(TradeCommand::WIRE_COLUMNS, explode("\t", $command->toWireLine()));

        $this->assertSame('modify', $columns['type']);
        $this->assertNotSame('', $columns['sl_price']);
        $this->assertSame((string) $trade->mt5_ticket, $columns['ticket']);
        // Zero would mean "remove the target"; empty means "leave it alone".
        $this->assertSame('', $columns['tp_price']);
    }

    // =====================================================================
    // SCOPE
    // =====================================================================

    public function test_closed_positions_are_not_managed(): void
    {
        $trade = $this->openTrade(lots: 1.00);
        $trade->update(['status' => 'fully_closed', 'closed_at' => now()]);

        $this->seedBarsReaching(self::TP1);
        $this->manage();

        $this->assertSame(0, TradeCommand::count());
    }

    public function test_a_partially_closed_position_is_still_managed(): void
    {
        $trade = $this->openTrade(lots: 1.00);
        $trade->update(['status' => 'partially_closed', 'remaining_lot_size' => 0.50]);

        $this->seedBarsReaching(self::TP2);
        $this->manage();

        $this->assertGreaterThan(0, TradeCommand::where('type', 'close')->count());
    }

    /**
     * Bars from before the position opened describe the setup, not the position. Counting
     * them would take a rung the trade never actually reached.
     */
    public function test_bars_from_before_the_entry_are_ignored(): void
    {
        $trade = $this->openTrade(lots: 1.00);

        // The spike to TP1 happens well before this position opened.
        $this->seedBarsReaching(self::TP1);
        $trade->update(['opened_at' => $this->lastBar->copy()->addMinutes(5)]);

        $this->manage();

        $this->assertSame(0, TradeCommand::count());
    }

    // =====================================================================
    // HELPERS
    // =====================================================================

    private function manage(): array
    {
        return app(TradeManager::class)->manage($this->strategy->fresh(), $this->account->id);
    }

    private function openTrade(float $lots, string $direction = 'buy'): Trade
    {
        $sign = $direction === 'buy' ? 1 : -1;

        return Trade::create([
            'user_id' => $this->user->id,
            'strategy_id' => $this->strategy->id,
            'broker_account_id' => $this->account->id,
            'mt5_ticket' => 900001,
            'symbol' => self::SYMBOL,
            'direction' => $direction,
            'initial_lot_size' => $lots,
            'remaining_lot_size' => $lots,
            'entry_price' => self::ENTRY,
            'sl_price' => self::ENTRY - ($sign * 5.00),
            'tp1_price' => self::ENTRY + ($sign * (self::TP1 - self::ENTRY)),
            'tp2_price' => self::ENTRY + ($sign * (self::TP2 - self::ENTRY)),
            'tp3_price' => self::ENTRY + ($sign * (self::TP3 - self::ENTRY)),
            'status' => 'open',
            // Far enough back that every seeded bar counts as "since entry".
            'opened_at' => $this->lastBar->copy()->subDays(1),
        ]);
    }

    private function fillRung(Trade $trade, string $rung, float $volume): void
    {
        TradePartial::create([
            'trade_id' => $trade->id,
            'mt5_deal_ticket' => random_int(100000, 999999),
            'closed_lot_size' => $volume,
            'close_price' => self::TP1,
            'close_reason' => $rung,
            'pips_profit' => 30,
            'gross_money_profit' => 150,
            'commission_money' => 0,
            'swap_money' => 0,
            'net_money_profit' => 150,
            'closed_at' => now(),
        ]);

        $trade->update([
            'remaining_lot_size' => (float) $trade->remaining_lot_size - $volume,
            'status' => 'partially_closed',
        ]);
    }

    /**
     * Seed a flat series whose final bars reach $extreme, in whichever direction it lies.
     */
    private function seedBarsReaching(float $extreme): void
    {
        $closes = array_fill(0, 60, self::ENTRY);
        $closes[] = $extreme;

        $this->seedBars($closes);
    }

    /**
     * @param  array<int, float>  $closes
     */
    private function seedBars(array $closes): void
    {
        $this->seedSeries(
            $closes,
            $this->strategy->timeframe_entry,
            $this->lastBar,
            $this->user->id,
            $this->account->id,
            self::SYMBOL,
        );
    }
}
