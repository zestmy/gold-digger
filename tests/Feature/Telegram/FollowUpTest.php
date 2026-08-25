<?php

namespace Tests\Feature\Telegram;

use App\Models\BotHeartbeat;
use App\Models\BotSettings;
use App\Models\BrokerAccount;
use App\Models\Candle;
use App\Models\Strategy;
use App\Models\SymbolSpec;
use App\Models\TelegramChannel;
use App\Models\TelegramSignal;
use App\Models\Trade;
use App\Models\TradeCommand;
use App\Models\User;
use App\Services\Telegram\FollowUpExecutor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Managing a position from a reply.
 *
 * The valuable tests here are the refusals. Taking half off when told to is easy to get
 * right and cheap to get wrong; obeying "move SL to 4590" on a losing long is neither.
 */
class FollowUpTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private BrokerAccount $account;

    private BotSettings $settings;

    private Strategy $strategy;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        config([
            'ai.key' => 'sk-or-test',
            'ai.base_url' => 'https://openrouter.ai/api/v1',
            'alerts.telegram.token' => 'bot-token',
            'alerts.telegram.chat_id' => '316745398',
        ]);

        $this->user = User::factory()->create();
        $this->strategy = Strategy::where('user_id', $this->user->id)->firstOrFail();

        $this->account = BrokerAccount::create([
            'user_id' => $this->user->id, 'label' => 'Demo', 'broker_name' => 'Elev8',
            'account_number' => '230070844', 'server' => 'Elev8-Demo2',
            'is_demo' => true, 'is_active' => true,
        ]);

        $this->settings = BotSettings::where('user_id', $this->user->id)->firstOrFail();
        $this->settings->update([
            'is_active' => true, 'allowed_sessions' => null, 'news_filter_enabled' => false,
            'ai_trading_enabled' => true, 'ai_capital_cap' => 200.00,
            'ai_risk_percentage' => 5.00, 'ai_max_concurrent_trades' => 3,
        ]);

        SymbolSpec::updateOrCreate(
            ['broker_account_id' => $this->account->id, 'symbol' => 'XAUUSD'],
            ['base_symbol' => 'XAUUSD', 'pip_size' => 0.10, 'digits' => 2,
                'pip_value_per_lot' => 10.0, 'volume_min' => 0.01, 'volume_step' => 0.01],
        );

        BotHeartbeat::create([
            'user_id' => $this->user->id, 'broker_account_id' => $this->account->id,
            'source' => 'mql5_ea', 'algo_trading_enabled' => true, 'broker_connected' => true,
            'resolved_symbol' => 'XAUUSD', 'pip_size' => 0.10, 'pip_value_per_lot' => 10.0,
            'volume_min' => 0.01, 'volume_step' => 0.01, 'balance' => 1000.00,
            'digits' => 2, 'last_seen_at' => now(),
        ]);

        for ($i = 60; $i > 0; $i--) {
            Candle::create([
                'user_id' => $this->user->id, 'broker_account_id' => $this->account->id,
                'symbol' => 'XAUUSD', 'timeframe' => 'M5', 'open_time' => now()->subMinutes(5 * $i),
                'open' => 2649, 'high' => 2652, 'low' => 2648, 'close' => 2650,
            ]);
        }

        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);
    }

    // =====================================================================
    // A STOP ONLY EVER MOVES TOWARD THE ENTRY
    // =====================================================================

    /**
     * The single most dangerous instruction a provider can send.
     */
    public function test_a_stop_may_not_be_widened_on_a_long(): void
    {
        // Long from 2650 with the stop at 2645. "More room" means 2640.
        $followUp = $this->followUp(TelegramSignal::FOLLOW_MOVE_STOP, price: 2640.0);

        $result = (new FollowUpExecutor)->execute($followUp);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('only ever moved toward the entry', $result['note']);
        $this->assertSame(0, TradeCommand::where('type', 'modify')->count());
    }

    public function test_a_stop_may_not_be_widened_on_a_short(): void
    {
        // Short from 2650 with the stop at 2655. "More room" means 2660.
        $followUp = $this->followUp(
            TelegramSignal::FOLLOW_MOVE_STOP,
            price: 2660.0,
            trade: ['direction' => 'sell', 'sl_price' => 2655.0],
        );

        $result = (new FollowUpExecutor)->execute($followUp);

        $this->assertFalse($result['ok']);
        $this->assertSame(0, TradeCommand::where('type', 'modify')->count());
    }

    public function test_a_stop_may_be_tightened(): void
    {
        $followUp = $this->followUp(TelegramSignal::FOLLOW_MOVE_STOP, price: 2648.0);

        $result = (new FollowUpExecutor)->execute($followUp);

        $this->assertTrue($result['ok'], $result['note']);

        $command = TradeCommand::where('type', 'modify')->firstOrFail();

        $this->assertEqualsWithDelta(2648.0, $command->payload['sl_price'], 1e-9);
        // Zero leaves the target alone; see CGDExecutor::ModifyPosition.
        $this->assertEqualsWithDelta(0.0, $command->payload['tp_price'], 1e-9);
    }

    public function test_breakeven_moves_the_stop_to_the_entry(): void
    {
        $followUp = $this->followUp(TelegramSignal::FOLLOW_BREAKEVEN);

        $this->assertTrue((new FollowUpExecutor)->execute($followUp)['ok']);

        $this->assertEqualsWithDelta(
            2650.0,
            TradeCommand::where('type', 'modify')->firstOrFail()->payload['sl_price'],
            1e-9,
        );
    }

    // =====================================================================
    // PARTIALS
    // =====================================================================

    public function test_secure_half_closes_half(): void
    {
        $followUp = $this->followUp(
            TelegramSignal::FOLLOW_PARTIAL,
            fraction: 0.5,
            trade: ['initial_lot_size' => 0.10, 'remaining_lot_size' => 0.10],
        );

        $this->assertTrue((new FollowUpExecutor)->execute($followUp)['ok']);

        $command = TradeCommand::where('type', 'close')->firstOrFail();

        $this->assertEqualsWithDelta(0.05, $command->payload['volume'], 1e-9);
        $this->assertSame(910001, $command->payload['ticket']);
    }

    /**
     * A remainder the broker cannot hold is not a partial close, it is a full one.
     */
    public function test_a_partial_that_would_leave_less_than_the_minimum_is_refused(): void
    {
        $followUp = $this->followUp(
            TelegramSignal::FOLLOW_PARTIAL,
            fraction: 0.5,
            // 0.01 is the minimum. Half of it is not a position.
            trade: ['initial_lot_size' => 0.01, 'remaining_lot_size' => 0.01],
        );

        $result = (new FollowUpExecutor)->execute($followUp);

        $this->assertFalse($result['ok']);
        $this->assertSame(0, TradeCommand::where('type', 'close')->count());
    }

    public function test_a_partial_is_snapped_down_not_up(): void
    {
        $followUp = $this->followUp(
            TelegramSignal::FOLLOW_PARTIAL,
            fraction: 0.5,
            trade: ['initial_lot_size' => 0.07, 'remaining_lot_size' => 0.07],
        );

        (new FollowUpExecutor)->execute($followUp);

        // 0.035 snaps to 0.03, never 0.04. Closing more than instructed books a winner
        // early, and the instruction said part.
        $this->assertEqualsWithDelta(
            0.03,
            TradeCommand::where('type', 'close')->firstOrFail()->payload['volume'],
            1e-9,
        );
    }

    public function test_close_closes_whatever_remains(): void
    {
        $followUp = $this->followUp(
            TelegramSignal::FOLLOW_CLOSE,
            trade: ['initial_lot_size' => 0.10, 'remaining_lot_size' => 0.04],
        );

        $this->assertTrue((new FollowUpExecutor)->execute($followUp)['ok']);

        $this->assertEqualsWithDelta(
            0.04,
            TradeCommand::where('type', 'close')->firstOrFail()->payload['volume'],
            1e-9,
        );
    }

    // =====================================================================
    // WHAT IT REFUSES TO MANAGE AT ALL
    // =====================================================================

    public function test_an_instruction_about_a_position_this_account_never_took_is_refused(): void
    {
        // The parent signal was captured but declined, so there is no trade. Acting would
        // mean managing somebody else's position.
        $followUp = $this->followUp(TelegramSignal::FOLLOW_CLOSE, withTrade: false);

        $result = (new FollowUpExecutor)->execute($followUp);

        $this->assertFalse($result['ok']);
        $this->assertSame(0, TradeCommand::count());
    }

    public function test_a_closed_position_is_not_managed(): void
    {
        $followUp = $this->followUp(
            TelegramSignal::FOLLOW_PARTIAL,
            fraction: 0.5,
            trade: ['status' => 'fully_closed', 'remaining_lot_size' => 0],
        );

        $result = (new FollowUpExecutor)->execute($followUp);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('already closed', $result['note']);
    }

    /**
     * Switching trading off is not a request to keep adjusting open positions.
     */
    public function test_the_kill_switch_stops_management_too(): void
    {
        $this->settings->update(['is_active' => false]);

        $result = (new FollowUpExecutor)->execute($this->followUp(TelegramSignal::FOLLOW_CLOSE));

        $this->assertFalse($result['ok']);
        $this->assertSame(0, TradeCommand::count());
    }

    public function test_the_same_instruction_cannot_be_carried_out_twice(): void
    {
        $followUp = $this->followUp(
            TelegramSignal::FOLLOW_PARTIAL,
            fraction: 0.5,
            trade: ['initial_lot_size' => 0.10, 'remaining_lot_size' => 0.10],
        );

        (new FollowUpExecutor)->execute($followUp);
        $second = (new FollowUpExecutor)->execute($followUp->fresh());

        // Without this a retry takes half off twice.
        $this->assertFalse($second['ok']);
        $this->assertSame(1, TradeCommand::where('type', 'close')->count());
    }

    // =====================================================================
    // THE RECORD OF WHAT IT DID
    // =====================================================================

    /**
     * Without this the only trace of an autonomous partial close is a Telegram message
     * that scrolled away.
     */
    public function test_the_copier_page_shows_the_instruction_thread_under_its_signal(): void
    {
        $followUp = $this->followUp(
            TelegramSignal::FOLLOW_PARTIAL,
            fraction: 0.5,
            trade: ['initial_lot_size' => 0.10, 'remaining_lot_size' => 0.10],
        );

        (new FollowUpExecutor)->execute($followUp);

        \Livewire\Livewire::actingAs($this->user)
            ->test(\App\Livewire\Pages\SignalCopier::class)
            ->assertSee('Instructions since')
            ->assertSee('Secure half')
            ->assertSee('secure partial');
    }

    /**
     * A reply is not a signal that failed to parse.
     */
    public function test_replies_are_not_listed_as_signals_in_their_own_right(): void
    {
        $this->followUp(TelegramSignal::FOLLOW_PARTIAL, fraction: 0.5);

        \Livewire\Livewire::actingAs($this->user)
            ->test(\App\Livewire\Pages\SignalCopier::class)
            // One signal listed, not two rows.
            ->assertViewHas('signals', fn ($signals) => $signals->total() === 1);
    }

    // =====================================================================
    // HELPERS
    // =====================================================================

    private function followUp(
        string $action,
        ?float $fraction = null,
        ?float $price = null,
        array $trade = [],
        bool $withTrade = true,
    ): TelegramSignal {
        $channel = TelegramChannel::create([
            'user_id' => $this->user->id, 'source' => TelegramChannel::SOURCE_ACCOUNT,
            'chat_id' => '5001', 'title' => 'FTC 2026', 'is_enabled' => true,
        ]);

        $row = null;

        if ($withTrade) {
            $row = Trade::create($trade + [
                'user_id' => $this->user->id,
                'strategy_id' => $this->strategy->id,
                'broker_account_id' => $this->account->id,
                'mt5_ticket' => 910001,
                'symbol' => 'XAUUSD', 'direction' => 'buy',
                'initial_lot_size' => 0.10, 'remaining_lot_size' => 0.10,
                'entry_price' => 2650.0, 'sl_price' => 2645.0,
                'status' => 'open', 'origin' => 'ai', 'opened_at' => now()->subMinutes(10),
            ]);
        }

        $parent = TelegramSignal::create([
            'user_id' => $this->user->id, 'source' => TelegramChannel::SOURCE_ACCOUNT,
            'kind' => TelegramSignal::KIND_SIGNAL,
            'external_id' => 'tg:5001:100', 'chat_id' => '5001',
            'telegram_channel_id' => $channel->id, 'chat_title' => 'FTC 2026',
            'raw_text' => "Gold Buy Now!\n@ 2650\nSL: 2645\nTP1: 2680",
            'posted_at' => now()->subMinutes(10),
            'parse_status' => TelegramSignal::PARSE_OK,
            'symbol' => 'XAUUSD', 'direction' => 'buy',
            'entry_price' => 2650.0, 'sl_price' => 2645.0, 'tp_prices' => [2680.0],
            'review_status' => TelegramSignal::REVIEW_APPROVED,
            'execution_status' => $withTrade ? TelegramSignal::EXEC_EXECUTED : TelegramSignal::EXEC_BLOCKED,
            'trade_id' => $row?->id,
        ]);

        return TelegramSignal::create([
            'user_id' => $this->user->id, 'source' => TelegramChannel::SOURCE_ACCOUNT,
            'kind' => TelegramSignal::KIND_FOLLOW_UP,
            'external_id' => 'tg:5001:101', 'chat_id' => '5001',
            'reply_to_message_id' => '100', 'parent_signal_id' => $parent->id,
            'telegram_channel_id' => $channel->id, 'chat_title' => 'FTC 2026',
            'raw_text' => 'Secure half', 'posted_at' => now(),
            'parse_status' => TelegramSignal::PARSE_OK,
            'review_status' => TelegramSignal::REVIEW_SKIPPED,
            'follow_up_action' => $action,
            'follow_up_fraction' => $fraction,
            'follow_up_price' => $price,
            'execution_status' => TelegramSignal::EXEC_NONE,
        ]);
    }
}
