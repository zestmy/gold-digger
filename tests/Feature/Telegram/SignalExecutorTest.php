<?php

namespace Tests\Feature\Telegram;

use App\Models\BotHeartbeat;
use App\Models\BotSettings;
use App\Models\BrokerAccount;
use App\Models\Candle;
use App\Models\SymbolSpec;
use App\Models\TelegramSignal;
use App\Models\Trade;
use App\Models\TradeCommand;
use App\Models\User;
use App\Services\Ai\AiFund;
use App\Services\Telegram\SignalExecutor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Executing an approved copied signal.
 *
 * This is the step that places real orders, so what it refuses matters more than what it
 * queues. Two properties above all: the position is sized from the AI fund and never from
 * the account balance, and every gate is re-checked here rather than trusted from an
 * approval made minutes ago.
 */
class SignalExecutorTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = 'https://openrouter.ai/api/v1/chat/completions';

    private User $user;

    private BotSettings $settings;

    private BrokerAccount $account;

    /** What the re-check at execution time should answer. See approve(). */
    private bool $approves = true;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        config(['ai.key' => 'sk-or-test', 'ai.base_url' => 'https://openrouter.ai/api/v1']);

        $this->user = User::factory()->create();
        $this->account = BrokerAccount::create([
            'user_id' => $this->user->id, 'label' => 'Demo', 'broker_name' => 'Elev8',
            'account_number' => '230070844', 'server' => 'Elev8-Demo2',
            'is_demo' => true, 'is_active' => true,
        ]);

        $this->settings = BotSettings::where('user_id', $this->user->id)->firstOrFail();
        $this->settings->update([
            'is_active' => true, 'allowed_sessions' => null, 'news_filter_enabled' => false,
            'ai_trading_enabled' => true, 'ai_capital_cap' => 500.00,
            'ai_risk_percentage' => 2.00, 'ai_max_concurrent_trades' => 1,
        ]);

        SymbolSpec::updateOrCreate(
            ['broker_account_id' => $this->account->id, 'symbol' => 'XAUUSD'],
            ['base_symbol' => 'XAUUSD',
             'pip_size' => 0.10, 'digits' => 2, 'pip_value_per_lot' => 10.0, 'volume_min' => 0.01, 'volume_step' => 0.01],
        );

        BotHeartbeat::create([
            'user_id' => $this->user->id, 'broker_account_id' => $this->account->id,
            'source' => 'mql5_ea', 'algo_trading_enabled' => true, 'broker_connected' => true,
            'resolved_symbol' => 'XAUUSD', 'pip_size' => 0.10, 'pip_value_per_lot' => 10.0,
            'volume_min' => 0.01, 'volume_step' => 0.01, 'balance' => 100000.00,
            'last_seen_at' => now(),
        ]);

        for ($i = 60; $i > 0; $i--) {
            Candle::create([
                'user_id' => $this->user->id, 'broker_account_id' => $this->account->id,
                'symbol' => 'XAUUSD', 'timeframe' => 'M5', 'open_time' => now()->subMinutes(5 * $i),
                'open' => 2649, 'high' => 2652, 'low' => 2648, 'close' => 2650,
            ]);
        }

        $this->approve();
        $this->fakeReviewer();
    }

    /**
     * The reviewer re-runs at execution time; keep it saying yes unless a test says otherwise.
     *
     * A closure stub rather than a second Http::fake(): fake() appends stubs and the first
     * match wins, so re-faking the same URL mid-test never takes effect - which silently
     * made the "conditions changed" test assert nothing at all.
     */
    private function approve(bool $yes = true): void
    {
        $this->approves = $yes;
    }

    private function fakeReviewer(): void
    {
        Http::fake([self::ENDPOINT => function () {
            return Http::response([
                'model' => 'test-model',
                'choices' => [['message' => ['content' => json_encode([
                    'approve' => $this->approves, 'confidence' => 80, 'reasoning' => 'Reward justifies risk.',
                ])]]],
            ]);
        }]);
    }

    private function signal(array $overrides = []): TelegramSignal
    {
        return TelegramSignal::create($overrides + [
            'user_id' => $this->user->id, 'source' => 'bot_api',
            'external_id' => 'bot:'.random_int(1, 999999), 'chat_id' => '316745398',
            'raw_text' => 'XAUUSD BUY 2650 SL 2640 TP 2680',
            'posted_at' => now()->subMinute(),
            'parse_status' => TelegramSignal::PARSE_OK,
            'symbol' => 'XAUUSD', 'direction' => 'buy',
            'entry_price' => 2650.0, 'sl_price' => 2640.0, 'tp_prices' => [2680.0],
            'review_status' => TelegramSignal::REVIEW_APPROVED,
            'execution_status' => TelegramSignal::EXEC_NONE,
        ]);
    }

    // =====================================================================
    // SIZING COMES FROM THE FUND
    // =====================================================================

    /**
     * The account holds 100,000. The fund holds 500. Only one of them may be risked.
     */
    public function test_the_position_is_sized_from_the_fund_not_the_balance(): void
    {
        $result = (new SignalExecutor)->execute($this->signal());

        $this->assertTrue($result['ok'], $result['note']);

        // 2% of a 500 fund is 10. Stop is 2650 - 2640 = 10 price = 100 pips at 0.10.
        // 10 / (100 * 10) = 0.01 lots.
        $command = TradeCommand::where('type', 'open')->firstOrFail();

        $this->assertEqualsWithDelta(0.01, $command->payload['volume'], 1e-9);
        $this->assertEqualsWithDelta(100.0, $command->payload['sl_pips'], 0.01);
    }

    public function test_the_order_carries_the_final_target_not_the_first(): void
    {
        (new SignalExecutor)->execute($this->signal(['tp_prices' => [2660.0, 2670.0, 2680.0]]));

        // An order stopped out at TP1 would close the whole position at a level meant to
        // take part of it - the same rule the strategy path follows.
        $this->assertEqualsWithDelta(300.0, TradeCommand::firstOrFail()->payload['tp_pips'], 0.01);
    }

    /**
     * Rounding up would risk more than the fund allows, which is the one thing the cap
     * exists to prevent.
     */
    public function test_lots_are_snapped_down_onto_the_volume_grid(): void
    {
        $this->settings->update(['ai_risk_percentage' => 3.7]);

        (new SignalExecutor)->execute($this->signal());

        $volume = TradeCommand::firstOrFail()->payload['volume'];

        // 3.7% of 500 = 18.50 risk, over a 100-pip stop at 10/pip = 0.0185 lots -> 0.01.
        $this->assertEqualsWithDelta(0.01, $volume, 1e-9);
    }

    public function test_a_fund_too_small_for_the_stop_is_refused_rather_than_rounded_up(): void
    {
        // 2% of 20 is 0.40, which over a 100-pip stop is well under the 0.01 minimum.
        $this->settings->update(['ai_capital_cap' => 20.00]);

        $signal = $this->signal();
        $result = (new SignalExecutor)->execute($signal);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('minimum', $result['note']);
        $this->assertSame(0, TradeCommand::count());
        $this->assertSame(TelegramSignal::EXEC_BLOCKED, $signal->fresh()->execution_status);
    }

    /**
     * The trap a small cap sets, in the configuration actually deployed.
     *
     * A fund cap is a loss budget, but the risk percentage taken from it has to survive
     * being divided by a stop distance and landing on the broker's volume grid. Gold at
     * 0.01 lots costs about 0.10 a pip, so a 2.00 budget buys a 20-pip stop - narrower
     * than gold trades - and every signal is refused for sizing while looking like a
     * strict reviewer.
     *
     * Both directions are asserted here because the failure is silent: nothing errors, the
     * copier simply never trades, and the channel analytics show a block rate that invites
     * exactly the wrong diagnosis.
     */
    public function test_a_two_hundred_fund_at_one_percent_cannot_fund_a_gold_stop(): void
    {
        $this->settings->update(['ai_capital_cap' => 200.00, 'ai_risk_percentage' => 1.00]);

        // 1% of 200 is 2.00. A 5.00 stop is 50 pips at 0.10, costing 500 a lot, so
        // 2.00 / 500 = 0.004 lots - below the 0.01 minimum, refused rather than rounded up.
        $result = (new SignalExecutor)->execute($this->signal(['sl_price' => 2645.0]));

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('minimum', $result['note']);
        $this->assertSame(0, TradeCommand::count());
    }

    public function test_a_two_hundred_fund_at_five_percent_funds_a_gold_stop(): void
    {
        $this->settings->update(['ai_capital_cap' => 200.00, 'ai_risk_percentage' => 5.00]);

        $result = (new SignalExecutor)->execute($this->signal(['sl_price' => 2645.0]));

        $this->assertTrue($result['ok'], $result['note']);

        // 5% of 200 is 10.00, over a 50-pip stop at 10 a pip per lot: 10 / 500 = 0.02 lots.
        $command = TradeCommand::firstOrFail();

        $this->assertEqualsWithDelta(0.02, $command->payload['volume'], 1e-9);
        $this->assertEqualsWithDelta(50.0, $command->payload['sl_pips'], 0.01);
    }

    public function test_it_refuses_to_size_without_a_pip_value(): void
    {
        SymbolSpec::where('symbol', 'XAUUSD')->update(['pip_value_per_lot' => null]);
        BotHeartbeat::where('user_id', $this->user->id)->update(['pip_value_per_lot' => null]);

        $result = (new SignalExecutor)->execute($this->signal());

        $this->assertFalse($result['ok']);
        $this->assertSame(0, TradeCommand::count(), 'Refusing to size beats guessing a pip value.');
    }

    // =====================================================================
    // THE GATES ARE RE-CHECKED HERE
    // =====================================================================

    /**
     * An approval describes the moment it was made, not a licence that stays valid.
     */
    public function test_conditions_changing_since_approval_block_execution(): void
    {
        $signal = $this->signal();

        // The reviewer now says no - a release came into range, say.
        $this->approve(false);

        $result = (new SignalExecutor)->execute($signal);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('Conditions changed', $result['note']);
        $this->assertSame(0, TradeCommand::count());
    }

    public function test_an_offline_executor_blocks_execution(): void
    {
        BotHeartbeat::where('user_id', $this->user->id)->update(['last_seen_at' => now()->subHour()]);

        $result = (new SignalExecutor)->execute($this->signal());

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('online', $result['note']);
    }

    public function test_algo_trading_off_blocks_execution(): void
    {
        // The order would come back 10027; queueing it would fill the queue with entries
        // that expire unfilled and bury the actual cause.
        BotHeartbeat::where('user_id', $this->user->id)->update(['algo_trading_enabled' => false]);

        $this->assertFalse((new SignalExecutor)->execute($this->signal())['ok']);
    }

    public function test_an_exhausted_fund_blocks_execution(): void
    {
        Trade::create([
            'user_id' => $this->user->id, 'broker_account_id' => $this->account->id,
            'strategy_id' => \App\Models\Strategy::where('user_id', $this->user->id)->value('id'),
            'origin' => AiFund::ORIGIN, 'symbol' => 'XAUUSD', 'direction' => 'buy',
            'initial_lot_size' => 0.01, 'remaining_lot_size' => 0.01, 'entry_price' => 2600,
            'status' => 'closed', 'net_pnl_money' => -500.00,
            'opened_at' => now()->subHour(), 'closed_at' => now(),
        ]);

        $this->assertFalse((new SignalExecutor)->execute($this->signal())['ok']);
        $this->assertSame(0, TradeCommand::count());
    }

    // =====================================================================
    // ONCE, AND ONLY ONCE
    // =====================================================================

    public function test_the_same_signal_cannot_queue_two_orders(): void
    {
        $signal = $this->signal();

        (new SignalExecutor)->execute($signal);
        (new SignalExecutor)->execute($signal->fresh());

        $this->assertSame(1, TradeCommand::count());
    }

    public function test_a_signal_that_was_never_approved_is_not_executed(): void
    {
        $result = (new SignalExecutor)->execute($this->signal([
            'review_status' => TelegramSignal::REVIEW_DECLINED,
        ]));

        $this->assertFalse($result['ok']);
        $this->assertSame(0, TradeCommand::count());
    }

    /**
     * Without this the resulting position records as `bot` and the fund never learns it
     * spent anything - which would remove the only bound on the whole feature.
     */
    public function test_the_command_marks_the_position_as_the_funds(): void
    {
        (new SignalExecutor)->execute($signal = $this->signal());

        $payload = TradeCommand::firstOrFail()->payload;

        $this->assertSame(AiFund::ORIGIN, $payload['origin']);
        $this->assertSame($signal->id, $payload['telegram_signal_id']);
    }

    public function test_the_command_expires_quickly(): void
    {
        (new SignalExecutor)->execute($this->signal());

        // A copied entry that sat in the queue is no longer the entry that was copied.
        $this->assertLessThanOrEqual(120, TradeCommand::firstOrFail()->expires_at->diffInSeconds(now()));
    }

    // =====================================================================
    // RESTING ORDERS
    // =====================================================================

    /**
     * A fund large enough that lot sizing is not at the 0.01 boundary.
     *
     * The sizing tests above deliberately sit on that edge; these are about where an order
     * rests, and a fixture that happens to round to zero lots would fail for an unrelated
     * reason and read as a bug in the resting logic.
     */
    private function richFund(): void
    {
        $this->settings->update(['ai_capital_cap' => 5000.00]);
    }

    /**
     * A signal naming an entry is asking to be filled there.
     */
    public function test_an_entry_away_from_the_market_rests_rather_than_filling(): void
    {
        // Market is 2650 from the seeded bars; the signal wants in at 2640.
        $this->richFund();
        $result = (new SignalExecutor)->execute($this->signal([
            'entry_price' => 2640.0, 'sl_price' => 2630.0, 'tp_prices' => [2670.0],
        ]));

        $this->assertTrue($result['ok'], $result['note']);

        $command = TradeCommand::firstOrFail();

        $this->assertSame('open_pending', $command->type);
        $this->assertEqualsWithDelta(2640.0, $command->payload['entry_price'], 1e-9);
    }

    /**
     * A resting order's stop belongs to its own entry, not to a market it has not touched.
     */
    public function test_a_resting_order_carries_absolute_levels_not_pip_distances(): void
    {
        $this->richFund();
        $r = (new SignalExecutor)->execute($this->signal([
            'entry_price' => 2640.0, 'sl_price' => 2630.0, 'tp_prices' => [2670.0],
        ]));
        $this->assertTrue($r['ok'], $r['note']);

        $payload = TradeCommand::firstOrFail()->payload;

        $this->assertEqualsWithDelta(2630.0, $payload['sl_price'], 1e-9);
        $this->assertEqualsWithDelta(2670.0, $payload['tp_price'], 1e-9);
        $this->assertNull($payload['sl_pips']);
        $this->assertNull($payload['tp_pips']);
    }

    /**
     * Resting an order a few cents away just delays the same fill.
     */
    public function test_an_entry_at_the_market_still_fills_at_market(): void
    {
        // Entry within a tenth of the stop distance of the last price.
        $this->richFund();
        $r = (new SignalExecutor)->execute($this->signal([
            'entry_price' => 2650.2, 'sl_price' => 2640.0, 'tp_prices' => [2680.0],
        ]));
        $this->assertTrue($r['ok'], $r['note']);

        $command = TradeCommand::firstOrFail();

        $this->assertSame('open', $command->type);
        $this->assertNull($command->payload['entry_price']);
        $this->assertEqualsWithDelta(102.0, $command->payload['sl_pips'], 0.5);
    }

    public function test_a_market_order_signal_never_rests(): void
    {
        // No entry at all means "at market", which is what the provider asked for.
        $this->richFund();
        (new SignalExecutor)->execute($this->signal(['entry_price' => null]));

        $this->assertSame('open', TradeCommand::firstOrFail()->type);
    }

    /**
     * The EA reads the entry off column thirteen; a short line would place a market order
     * at whatever price happened to be available.
     */
    public function test_the_wire_line_carries_the_entry_in_its_own_column(): void
    {
        $this->richFund();
        $r = (new SignalExecutor)->execute($this->signal([
            'entry_price' => 2640.0, 'sl_price' => 2630.0, 'tp_prices' => [2670.0],
        ]));
        $this->assertTrue($r['ok'], $r['note']);

        $columns = explode("	", TradeCommand::firstOrFail()->toWireLine());

        $this->assertCount(count(TradeCommand::WIRE_COLUMNS), $columns);
        $this->assertSame('open_pending', $columns[1]);
        $this->assertSame('2640', $columns[array_search('entry_price', TradeCommand::WIRE_COLUMNS, true)]);
    }
}
