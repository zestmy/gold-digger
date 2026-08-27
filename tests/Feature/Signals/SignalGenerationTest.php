<?php

namespace Tests\Feature\Signals;

use App\Models\BotHeartbeat;
use App\Models\BotSettings;
use App\Models\BrokerAccount;
use App\Models\Candle;
use App\Models\EconomicEvent;
use App\Models\Signal;
use App\Models\Strategy;
use App\Models\Trade;
use App\Models\TradeCommand;
use App\Models\TradePartial;
use App\Models\User;
use App\Services\Strategy\SignalGenerator;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Tests\Support\MakesPriceSeries;
use Tests\TestCase;

/**
 * Signal generation.
 *
 * The strategy layer decides whether real money is put at risk and how much of it, so
 * these tests care as much about the refusals as about the entries. Every filter has a
 * test that proves it blocks, and proves that blocking records the reason rather than
 * silently dropping the setup - because "why did the bot not trade that" is unanswerable
 * without the row.
 */
class SignalGenerationTest extends TestCase
{
    use MakesPriceSeries;
    use RefreshDatabase;

    private User $user;

    private BrokerAccount $account;

    private Strategy $strategy;

    private BotSettings $settings;

    /** Bar that closes inside the London session, so the default session gate is open. */
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

        // UserObserver seeds both of these; the defaults are just not the ones these
        // tests want to hold constant.
        $this->settings = BotSettings::where('user_id', $this->user->id)->firstOrFail();
        $this->settings->update([
            'is_active' => true,
            'risk_percentage' => 1.00,
            'max_daily_loss_percentage' => 3.00,
            'max_concurrent_trades' => 3,
            // Session filtering has its own test. Leaving it unset here keeps every other
            // test from depending on what time of day the fixture bars land on.
            'allowed_sessions' => null,
            // Likewise the news filter, which UserObserver defaults to on. It fails closed
            // when no calendar is present - correct behaviour, and it would otherwise be
            // the first objection every one of these tests hit, masking the gate each is
            // actually about. NewsBlackoutTest covers the filter itself.
            'news_filter_enabled' => false,
            'min_atr_threshold' => null,
        ]);

        $this->strategy = Strategy::where('user_id', $this->user->id)->firstOrFail();
        $this->strategy->update([
            'is_active' => true,
            'symbol' => 'XAUUSD',
            // ADX has its own test too. At zero, every setup clears the gate.
            'adx_threshold' => 0,
        ]);

        $this->lastBar = Carbon::parse('2026-03-10 13:00:00', 'UTC');

        $this->heartbeat();
    }

    // =====================================================================
    // THE HAPPY PATH
    // =====================================================================

    public function test_a_confirmed_crossover_records_a_signal_and_queues_an_open_command(): void
    {
        $this->seedBullishSetup();

        $signal = $this->generate();

        $this->assertNotNull($signal);
        $this->assertSame('buy', $signal->direction);
        $this->assertNull($signal->skip_reason);
        $this->assertSame(self::SYMBOL, $signal->symbol);

        $command = TradeCommand::where('type', 'open')->first();

        $this->assertNotNull($command, 'A signal that clears every filter must queue an open command.');
        $this->assertSame('pending', $command->status);
        $this->assertSame('buy', $command->payload['direction']);
        $this->assertSame(self::SYMBOL, $command->payload['symbol']);
        $this->assertGreaterThan(0, $command->payload['volume']);
        $this->assertSame("signal:{$signal->id}", $command->idempotency_key);
    }

    /**
     * The command must not outlive the bar that justified it. An entry filled after the
     * next bar has closed is executing a setup that no longer exists.
     */
    public function test_the_open_command_expires_within_one_entry_bar(): void
    {
        $this->seedBullishSetup();
        $this->generate();

        $command = TradeCommand::where('type', 'open')->firstOrFail();

        $this->assertNotNull($command->expires_at);
        // M5 is the strategy's entry timeframe.
        $this->assertLessThanOrEqual(300, $command->expires_at->diffInSeconds(now()));
    }

    /**
     * Risk 1% of 10,000 is 100. The stop distance is whatever 1.5 ATR works out to, and
     * a pip of one lot is worth 10 - so the lots must be exactly 100 / (stop * 10).
     */
    public function test_position_size_risks_the_configured_percentage_of_balance(): void
    {
        $this->seedBullishSetup();

        $signal = $this->generate();

        $stopPips = $signal->features['sl_pips'];
        $expected = round(100.0 / ($stopPips * 10.0), 4);

        $this->assertEqualsWithDelta($expected, (float) $signal->suggested_lot_size, 1e-4);
    }

    /**
     * The stop is 1.5 ATR below entry on a buy, and the pip figure sent to the EA must be
     * that same distance expressed in the terminal's pips.
     */
    public function test_the_stop_sits_at_the_configured_atr_multiple_below_entry(): void
    {
        $this->seedBullishSetup();

        $signal = $this->generate();

        $atr = $signal->features['atr'];
        $expectedDistance = 1.5 * $atr;

        $this->assertEqualsWithDelta(
            (float) $signal->entry_price - $expectedDistance,
            (float) $signal->sl_price,
            0.001,
        );

        // pip_size is 0.10, so the distance in pips is ten times the price distance.
        $this->assertEqualsWithDelta($expectedDistance / 0.10, $signal->features['sl_pips'], 0.01);
    }

    /**
     * The order carries the *final* target, not TP1. Partial closes at TP1/TP2 need a
     * trade-management loop that does not exist yet, so an order stopped out at TP1 would
     * close the whole position at a level meant to take half of it.
     */
    public function test_the_order_target_is_the_final_ladder_step_not_the_first(): void
    {
        $this->seedBullishSetup();

        $this->generate();

        $command = TradeCommand::where('type', 'open')->firstOrFail();

        // Defaults are TP1 30, TP2 100, TP3 200.
        $this->assertEqualsWithDelta(200.0, (float) $command->payload['tp_pips'], 1e-9);
    }

    /**
     * Stops travel as pips so the EA can place them against the tick it actually fills at.
     * If an absolute price were supplied, CFXSExecutor would use it verbatim and the real
     * risk would differ from the intended risk by however far price moved in between.
     */
    public function test_the_wire_line_carries_pips_and_leaves_the_price_columns_empty(): void
    {
        $this->seedBullishSetup();
        $this->generate();

        $command = TradeCommand::where('type', 'open')->firstOrFail();
        $fields = explode("\t", $command->toWireLine());

        $columns = array_combine(TradeCommand::WIRE_COLUMNS, $fields);

        $this->assertNotSame('', $columns['sl_pips']);
        $this->assertNotSame('', $columns['tp_pips']);
        $this->assertSame('', $columns['sl_price'], 'An absolute stop would override the EA tick-relative placement.');
        $this->assertSame('', $columns['tp_price']);
    }

    /**
     * The whole point of the ladder levels being on the signal is that FillController
     * reads them when it writes the trade row.
     */
    public function test_the_command_payload_carries_the_ladder_and_its_origin(): void
    {
        $this->seedBullishSetup();

        $signal = $this->generate();
        $payload = TradeCommand::where('type', 'open')->firstOrFail()->payload;

        $this->assertSame($this->strategy->id, $payload['strategy_id']);
        $this->assertSame($signal->id, $payload['signal_id']);
        $this->assertNotNull($payload['tp1_price']);
        $this->assertNotNull($payload['tp2_price']);
        $this->assertNotNull($payload['tp3_price']);
    }

    // =====================================================================
    // IDEMPOTENCE
    // =====================================================================

    /**
     * The EA re-pushes a trailing window of bars on every poll so a dropped request
     * self-heals. Without a per-bar guard that would open a position per poll.
     */
    public function test_re_evaluating_the_same_bar_does_not_signal_twice(): void
    {
        $this->seedBullishSetup();

        $first = $this->generate();
        $second = $this->generate();

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Signal::count());
        $this->assertSame(1, TradeCommand::where('type', 'open')->count());
    }

    /**
     * The application-level check avoids the write on the ordinary path, but two
     * overlapping candle pushes can both pass it. Two signals for one bar would mean two
     * command idempotency keys and a duplicate position, so the database enforces it too.
     */
    public function test_the_database_refuses_a_second_signal_on_the_same_bar(): void
    {
        $this->seedBullishSetup();
        $signal = $this->generate();

        $this->expectException(UniqueConstraintViolationException::class);

        Signal::create([
            'strategy_id' => $signal->strategy_id,
            'symbol' => $signal->symbol,
            'timeframe' => $signal->timeframe,
            'direction' => 'sell',
            'entry_price' => 2000,
            'sl_price' => 1990,
            'generated_at' => $signal->generated_at,
        ]);
    }

    // =====================================================================
    // WHEN THE RULES DO NOT FIRE
    // =====================================================================

    /**
     * No cross means no setup, and no row. A signal per bar would bury the rows that
     * matter under one per five minutes forever.
     */
    public function test_a_series_with_no_crossover_records_nothing(): void
    {
        $this->seedBars($this->trendCloses(300, rising: true), 'M5');
        $this->seedBars($this->trendCloses(80, rising: true), 'H1');

        $this->assertNull($this->generate());
        $this->assertSame(0, Signal::count());
    }

    /**
     * The higher timeframe has a veto. A bullish cross while H1 points down is the
     * counter-trend entry that whipsaws, and it is rejected before it becomes a signal.
     */
    public function test_a_cross_against_the_higher_timeframe_trend_is_not_a_setup(): void
    {
        $this->seedBars($this->crossCloses('buy'), 'M5');
        // Bearish trend series: fast EMA below slow.
        $this->seedBars($this->trendCloses(80, rising: false), 'H1');

        $this->assertNull($this->generate());
        $this->assertSame(0, Signal::count());
    }

    public function test_an_inactive_strategy_is_never_evaluated(): void
    {
        $this->seedBullishSetup();
        $this->strategy->update(['is_active' => false]);

        $this->assertNull($this->generate());
        $this->assertSame(0, Signal::count());
    }

    public function test_a_series_shorter_than_the_indicator_warm_up_produces_nothing(): void
    {
        $this->seedBars(array_slice($this->crossCloses('buy'), -30), 'M5');
        $this->seedBars($this->trendCloses(80, rising: true), 'H1');

        $this->assertNull($this->generate());
    }

    // =====================================================================
    // FILTERS - each records the setup, with its reason, and queues nothing
    // =====================================================================

    public function test_the_kill_switch_records_the_setup_but_queues_nothing(): void
    {
        $this->seedBullishSetup();
        $this->settings->update(['is_active' => false]);

        $signal = $this->generate();

        $this->assertSame('bot_inactive', $signal->skip_reason);
        $this->assertFalse($signal->was_executed);
        $this->assertSame(0, TradeCommand::count());
    }

    /**
     * A terminal with Algo Trading switched off still heartbeats and still pushes bars,
     * so setups keep appearing - but every order sent to it comes back 10027. Queueing
     * into that would fill the queue with entries that expire unfilled and bury the
     * actual cause.
     */
    public function test_a_terminal_with_algo_trading_off_records_the_setup_but_queues_nothing(): void
    {
        $this->seedBullishSetup();
        BotHeartbeat::where('user_id', $this->user->id)->update(['algo_trading_enabled' => false]);

        $signal = $this->generate();

        $this->assertSame('algo_trading_disabled', $signal->skip_reason);
        $this->assertSame(0, TradeCommand::count());
    }

    public function test_a_weak_trend_is_skipped_with_the_adx_reason(): void
    {
        $this->seedBullishSetup();
        $this->strategy->update(['adx_threshold' => 99.99]);

        $signal = $this->generate();

        $this->assertSame('adx_below_threshold', $signal->skip_reason);
        $this->assertSame(0, TradeCommand::count());
    }

    public function test_a_quiet_market_is_skipped_with_the_atr_reason(): void
    {
        $this->seedBullishSetup();
        $this->settings->update(['min_atr_threshold' => 9999]);

        $signal = $this->generate();

        $this->assertSame('atr_below_threshold', $signal->skip_reason);
        $this->assertSame(0, TradeCommand::count());
    }

    /**
     * The fixture bar closes at 13:00 UTC, which is inside London and New York but well
     * outside the Asian window.
     */
    public function test_a_bar_outside_the_allowed_sessions_is_skipped(): void
    {
        $this->seedBullishSetup();
        $this->settings->update(['allowed_sessions' => ['asian']]);

        $signal = $this->generate();

        $this->assertSame('session_closed', $signal->skip_reason);
        $this->assertSame(0, TradeCommand::count());
    }

    public function test_a_bar_inside_an_allowed_session_is_not_skipped(): void
    {
        $this->seedBullishSetup();
        $this->settings->update(['allowed_sessions' => ['london', 'overlap']]);

        $this->assertNull($this->generate()->skip_reason);
    }

    /**
     * The news filter, end to end through the signal path.
     *
     * `news_filter_enabled` was a settings toggle with nothing behind it until the
     * calendar existed. NewsBlackoutTest covers the window arithmetic; these prove the
     * gate is actually wired into signal generation and reports itself distinctly.
     */
    public function test_a_bar_inside_a_news_blackout_is_skipped(): void
    {
        $this->seedBullishSetup();
        $this->settings->update(['news_filter_enabled' => true]);
        $this->calendarEvent('2026-03-10 13:05:00');

        $signal = $this->generate();

        $this->assertSame('news_blackout', $signal->skip_reason);
        $this->assertSame(0, TradeCommand::count());
    }

    public function test_a_bar_clear_of_the_calendar_is_not_skipped(): void
    {
        $this->seedBullishSetup();
        $this->settings->update(['news_filter_enabled' => true]);
        // Six hours away, far outside the 15-minute window.
        $this->calendarEvent('2026-03-10 19:00:00');

        $this->assertNull($this->generate()->skip_reason);
    }

    /**
     * The direction this fails in is the whole point of the design.
     */
    public function test_the_filter_holds_entries_when_there_is_no_calendar_to_check(): void
    {
        $this->seedBullishSetup();
        $this->settings->update(['news_filter_enabled' => true]);
        // No events loaded at all.

        $signal = $this->generate();

        $this->assertSame('news_data_stale', $signal->skip_reason);
        $this->assertSame(0, TradeCommand::count(), 'An unverifiable filter must not let an entry through.');
    }

    private function calendarEvent(string $scheduledAtUtc): void
    {
        Cache::flush();

        EconomicEvent::create([
            'external_id' => 'sig-test-'.md5($scheduledAtUtc),
            'title' => 'Non-Farm Employment Change',
            'currency' => 'USD',
            'impact' => 'high',
            'scheduled_at' => Carbon::parse($scheduledAtUtc, 'UTC'),
            'fetched_at' => now(),
        ]);
    }

    /**
     * Without the terminal's pip size there is no honest stop distance and no honest lot
     * size. Guessing gold is ten points a pip is exactly the pip trap.
     */
    public function test_an_unknown_pip_size_blocks_execution_rather_than_guessing(): void
    {
        $this->seedBullishSetup();
        BotHeartbeat::where('user_id', $this->user->id)->update(['pip_size' => null]);

        $signal = $this->generate();

        $this->assertSame('no_symbol_spec', $signal->skip_reason);
        $this->assertNull($signal->suggested_lot_size);
        $this->assertSame(0, TradeCommand::count());
    }

    /**
     * Pip value depends on contract size and the deposit currency. A wrong value here
     * does not fail loudly - it trades a size nobody chose.
     */
    public function test_an_unknown_pip_value_blocks_sizing(): void
    {
        $this->seedBullishSetup();
        BotHeartbeat::where('user_id', $this->user->id)->update(['pip_value_per_lot' => null]);

        $signal = $this->generate();

        $this->assertSame('lot_size_unavailable', $signal->skip_reason);
        $this->assertSame(0, TradeCommand::count());
    }

    public function test_the_concurrent_trade_cap_is_enforced(): void
    {
        $this->seedBullishSetup();
        $this->settings->update(['max_concurrent_trades' => 2]);

        $this->openTrade(1001);
        $this->openTrade(1002);

        $signal = $this->generate();

        $this->assertSame('max_trades_reached', $signal->skip_reason);
        $this->assertSame(0, TradeCommand::where('type', 'open')->count());
    }

    /**
     * 3% of a 10,000 opening balance is 300. A realised loss of 400 today is past it.
     */
    public function test_the_daily_loss_limit_halts_new_entries(): void
    {
        $this->seedBullishSetup();
        $this->losingTradeToday(-400.0);

        $signal = $this->generate();

        $this->assertSame('daily_loss_limit', $signal->skip_reason);
        $this->assertSame(0, TradeCommand::where('type', 'open')->count());
    }

    public function test_a_realised_loss_inside_the_limit_does_not_halt_entries(): void
    {
        $this->seedBullishSetup();
        $this->losingTradeToday(-50.0);

        $this->assertNull($this->generate()->skip_reason);
    }

    /**
     * Floating loss is not realised loss. A limit that tripped on an open position would
     * halt trading over a drawdown that may recover within the hour.
     */
    public function test_an_open_losing_position_does_not_count_towards_the_daily_limit(): void
    {
        $this->seedBullishSetup();

        $trade = $this->openTrade(2001);
        $trade->update(['net_pnl_money' => -900.0]);

        $this->assertNull($this->generate()->skip_reason);
    }

    // =====================================================================
    // FEATURES
    // =====================================================================

    /**
     * The features blob is what makes a skipped signal worth having: it is the evidence
     * for "were the filters too strict".
     */
    public function test_the_signal_records_the_readings_the_decision_was_made_from(): void
    {
        $this->seedBullishSetup();

        $features = $this->generate()->features;

        foreach (['ema_fast', 'ema_slow', 'adx', 'atr', 'sl_pips', 'trend_direction', 'pip_size'] as $key) {
            $this->assertArrayHasKey($key, $features, "features should record {$key}");
        }

        $this->assertSame('buy', $features['trend_direction']);
    }

    // =====================================================================
    // THE REWARD FLOOR
    // =====================================================================

    /**
     * The default that matters most: an account that has not asked for a reward floor
     * trades exactly as it did before one existed.
     */
    public function test_no_reward_floor_is_applied_unless_one_is_configured(): void
    {
        $this->seedBullishSetup();

        // A ladder worth far less than the stop it sits behind - taken, because nobody
        // asked for it not to be.
        $this->strategy->update(['tp1_pips' => 1.0, 'tp2_pips' => 1.0, 'tp3_pips' => null]);

        $signal = $this->generate();

        $this->assertNull($signal->skip_reason);
        $this->assertSame(1, TradeCommand::where('type', 'open')->count());
    }

    public function test_a_configured_floor_refuses_a_setup_that_does_not_pay_for_its_stop(): void
    {
        $this->seedBullishSetup();
        $this->settings->update(['min_reward_ratio' => 2.0]);
        $this->strategy->update(['tp1_pips' => 1.0, 'tp2_pips' => 1.0, 'tp3_pips' => null]);

        $signal = $this->generate();

        $this->assertSame('reward_below_floor', $signal->skip_reason);
        $this->assertFalse($signal->was_executed);
        $this->assertSame(0, TradeCommand::count(), 'A refused setup must not queue an order.');
    }

    /**
     * Measured to the take-profit the order actually carries. A generous first rung does
     * not rescue a setup whose final target is close, because the position runs to the
     * last one or to the stop.
     */
    public function test_the_floor_judges_the_target_the_order_carries(): void
    {
        $this->seedBullishSetup();
        $this->settings->update(['min_reward_ratio' => 2.0]);

        $signal = $this->generate();

        $this->assertNotNull($signal);

        // Whatever the verdict, it was reached from the order's own target rather than
        // from an intermediate rung - so the recorded stop and target agree with it.
        $slPips = (float) $signal->features['sl_pips'];
        $tpPips = (float) ($signal->features['order_tp_pips'] ?? 0.0);
        $ratio = $slPips > 0.0 ? $tpPips / $slPips : 0.0;

        $this->assertSame(
            $ratio < 2.0 ? 'reward_below_floor' : null,
            $signal->skip_reason,
            sprintf('Offered %.2f : 1 against a floor of 2.', $ratio),
        );
    }

    // =====================================================================
    // HELPERS
    // =====================================================================

    private function generate(): ?Signal
    {
        return app(SignalGenerator::class)->generate($this->strategy->fresh(), $this->account->id);
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
                // Gold: one lot is 100 ounces, so a 0.10 move is worth 10.
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

    /**
     * @param  array<int, float>  $closes
     */
    private function seedBars(array $closes, string $timeframe): void
    {
        $this->seedSeries($closes, $timeframe, $this->lastBar, $this->user->id, $this->account->id, self::SYMBOL);
    }

    private function openTrade(int $ticket): Trade
    {
        return Trade::create([
            'user_id' => $this->user->id,
            'strategy_id' => $this->strategy->id,
            'broker_account_id' => $this->account->id,
            'mt5_ticket' => $ticket,
            'symbol' => self::SYMBOL,
            'direction' => 'buy',
            'initial_lot_size' => 0.10,
            'remaining_lot_size' => 0.10,
            'entry_price' => 2300.00,
            'sl_price' => 2290.00,
            'status' => 'open',
            'opened_at' => now(),
        ]);
    }

    private function losingTradeToday(float $net): void
    {
        $trade = $this->openTrade(3001);

        $trade->update(['status' => 'fully_closed', 'remaining_lot_size' => 0, 'closed_at' => now()]);

        TradePartial::create([
            'trade_id' => $trade->id,
            'mt5_deal_ticket' => 9001,
            'closed_lot_size' => 0.10,
            'close_price' => 2280.00,
            'close_reason' => 'sl',
            'pips_profit' => -200,
            'gross_money_profit' => $net,
            'commission_money' => 0,
            'swap_money' => 0,
            'net_money_profit' => $net,
            'closed_at' => now(),
        ]);
    }
}
