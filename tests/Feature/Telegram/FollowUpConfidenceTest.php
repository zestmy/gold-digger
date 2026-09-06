<?php

namespace Tests\Feature\Telegram;

use App\Console\Commands\ManageTelegramFollowUps;
use App\Models\AiUsage;
use App\Models\BotHeartbeat;
use App\Models\BotSettings;
use App\Models\BrokerAccount;
use App\Models\Strategy;
use App\Models\SymbolSpec;
use App\Models\TelegramChannel;
use App\Models\TelegramSignal;
use App\Models\Trade;
use App\Models\TradeCommand;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Acting on a reply only when the reading is sure enough to act on.
 *
 * The interpreter's prompt tells the model that anything under sixty should almost
 * always be `none`, and the schema says the same. A prompt is a request. This is the
 * gate that makes it a rule, in the one place the reading is turned into an order.
 */
class FollowUpConfidenceTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private BrokerAccount $account;

    private BotSettings $settings;

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
    }

    public function test_a_reading_below_the_line_is_treated_as_nothing_instructed(): void
    {
        $this->modelReads('secure_partial', confidence: ManageTelegramFollowUps::MIN_CONFIDENCE - 1);
        $followUp = $this->unreadFollowUp();

        $this->artisan('telegram:follow-up', ['--quiet-announce' => true])->assertSuccessful();

        $followUp->refresh();

        $this->assertSame(TelegramSignal::FOLLOW_NONE, $followUp->follow_up_action);
        $this->assertNull($followUp->follow_up_fraction);
        $this->assertSame(TelegramSignal::EXEC_NONE, $followUp->execution_status);
        $this->assertSame(0, TradeCommand::count());
    }

    /**
     * The record still says what the model thought it read, so a provider whose
     * instructions keep landing just under the line is something you can see.
     */
    public function test_the_record_says_what_was_read_and_why_it_was_not_acted_on(): void
    {
        $this->modelReads('secure_partial', confidence: 35);
        $followUp = $this->unreadFollowUp();

        $this->artisan('telegram:follow-up', ['--quiet-announce' => true])->assertSuccessful();

        $followUp->refresh();

        $this->assertSame(35, (int) $followUp->review_confidence);
        $this->assertStringContainsString('secure_partial', $followUp->review_reasoning);
        $this->assertStringContainsString('35%', $followUp->review_reasoning);
        $this->assertStringContainsString('below the 60%', $followUp->review_reasoning);
    }

    public function test_a_reading_on_the_line_is_acted_on(): void
    {
        $this->modelReads('secure_partial', confidence: ManageTelegramFollowUps::MIN_CONFIDENCE);
        $followUp = $this->unreadFollowUp();

        $this->artisan('telegram:follow-up', ['--quiet-announce' => true])->assertSuccessful();

        $followUp->refresh();

        $this->assertSame(TelegramSignal::FOLLOW_PARTIAL, $followUp->follow_up_action);
        $this->assertSame(TelegramSignal::EXEC_QUEUED, $followUp->execution_status);
        $this->assertSame(1, TradeCommand::where('type', 'close')->count());
    }

    /**
     * The interpreter's own refusals carry no confidence at all, and are already `none`.
     */
    public function test_a_confident_none_is_left_alone(): void
    {
        $this->modelReads('none', confidence: 95);
        $followUp = $this->unreadFollowUp();

        $this->artisan('telegram:follow-up', ['--quiet-announce' => true])->assertSuccessful();

        $followUp->refresh();

        $this->assertSame(TelegramSignal::FOLLOW_NONE, $followUp->follow_up_action);
        $this->assertStringNotContainsString('below', $followUp->review_reasoning);
    }

    /**
     * The reading is a model call about somebody's position. It is their call, on their
     * allowance.
     */
    public function test_the_interpretation_is_charged_to_the_positions_owner(): void
    {
        $this->modelReads('secure_partial', confidence: 80);
        $this->unreadFollowUp();

        $this->artisan('telegram:follow-up', ['--quiet-announce' => true])->assertSuccessful();

        $usage = AiUsage::acrossTenants()->where('call_site', 'follow_up_interpreter')->sole();

        $this->assertSame($this->user->id, $usage->user_id);
    }

    // =====================================================================
    // HELPERS
    // =====================================================================

    private function modelReads(string $action, int $confidence): void
    {
        Http::fake(['openrouter.ai/*' => Http::response([
            'model' => 'test-model',
            'choices' => [['message' => ['content' => json_encode([
                'action' => $action,
                'fraction' => $action === 'secure_partial' ? 0.5 : null,
                'price' => null,
                'confidence' => $confidence,
                'reasoning' => 'The words carry it, more or less.',
            ])]]],
        ], 200)]);
    }

    /**
     * A reply that has not yet been interpreted, on a signal that became a position.
     */
    private function unreadFollowUp(): TelegramSignal
    {
        $channel = TelegramChannel::create([
            'user_id' => $this->user->id, 'source' => TelegramChannel::SOURCE_ACCOUNT,
            'chat_id' => '5001', 'title' => 'FTC 2026', 'is_enabled' => true,
        ]);

        $trade = Trade::create([
            'user_id' => $this->user->id,
            'strategy_id' => Strategy::where('user_id', $this->user->id)->value('id'),
            'broker_account_id' => $this->account->id,
            'mt5_ticket' => 910001,
            'symbol' => 'XAUUSD', 'direction' => 'buy',
            'initial_lot_size' => 0.10, 'remaining_lot_size' => 0.10,
            'entry_price' => 2650.0, 'sl_price' => 2645.0, 'initial_sl_price' => 2645.0,
            'status' => 'open', 'origin' => 'ai', 'opened_at' => now()->subMinutes(10),
        ]);

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
            'execution_status' => TelegramSignal::EXEC_EXECUTED,
            'trade_id' => $trade->id,
        ]);

        return TelegramSignal::create([
            'user_id' => $this->user->id, 'source' => TelegramChannel::SOURCE_ACCOUNT,
            'kind' => TelegramSignal::KIND_FOLLOW_UP,
            'external_id' => 'tg:5001:101', 'chat_id' => '5001',
            'reply_to_message_id' => '100', 'parent_signal_id' => $parent->id,
            'telegram_channel_id' => $channel->id, 'chat_title' => 'FTC 2026',
            'raw_text' => 'secure some maybe', 'posted_at' => now(),
            'parse_status' => TelegramSignal::PARSE_OK,
            'review_status' => TelegramSignal::REVIEW_SKIPPED,
            'follow_up_action' => null,
            'execution_status' => TelegramSignal::EXEC_NONE,
        ]);
    }
}
