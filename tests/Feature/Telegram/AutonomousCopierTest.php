<?php

namespace Tests\Feature\Telegram;

use App\Models\BotHeartbeat;
use App\Models\BotLog;
use App\Models\BotSettings;
use App\Models\BrokerAccount;
use App\Models\Candle;
use App\Models\SymbolSpec;
use App\Models\TelegramChannel;
use App\Models\TelegramSignal;
use App\Models\TradeCommand;
use App\Models\User;
use App\Services\Telegram\SignalExecutor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The copier running without anybody present.
 *
 * Scheduling execution changes one thing that matters: nobody is watching. So the
 * properties worth holding are about what happens when a position opens and there is no
 * human in the loop - it gets announced, it gets recorded whether or not the announcement
 * lands, and pressing the button yourself does not produce a message telling you what you
 * just did.
 */
class AutonomousCopierTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private BrokerAccount $account;

    private BotSettings $settings;

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

        // The chat now belongs to the tenant rather than to the deployment - see
        // AlertNotifier::destinationFor. The configured chat above is the platform's own
        // address and is deliberately never used for a customer's position.
        $this->user = User::factory()->create(['telegram_chat_id' => '316745398']);
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
            'copier_levels' => 'provider',
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
            'last_seen_at' => now(),
        ]);

        for ($i = 60; $i > 0; $i--) {
            Candle::create([
                'user_id' => $this->user->id, 'broker_account_id' => $this->account->id,
                'symbol' => 'XAUUSD', 'timeframe' => 'M5', 'open_time' => now()->subMinutes(5 * $i),
                'open' => 2649, 'high' => 2652, 'low' => 2648, 'close' => 2650,
            ]);
        }

        $this->fakeTelegramAndReviewer();
    }

    // =====================================================================
    // IT TELLS YOU
    // =====================================================================

    public function test_an_unattended_order_is_announced(): void
    {
        $this->artisan('telegram:execute')->assertSuccessful();

        $this->assertSame(1, TradeCommand::count());

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'sendMessage')
                && str_contains($request['text'], 'Copier opened')
                && str_contains($request['text'], 'XAUUSD');
        });
    }

    /**
     * The message has to be judgeable without opening the dashboard.
     */
    public function test_the_announcement_carries_the_size_the_source_and_the_risk(): void
    {
        $this->artisan('telegram:execute')->assertSuccessful();

        $text = $this->announcementText();

        $this->assertStringContainsString('0.02', $text, 'the size it opened');
        $this->assertStringContainsString('Fira', $text, 'whose signal it was');
        $this->assertStringContainsString('10', $text, 'what it risks');
        $this->assertStringContainsString('scheduler', $text, 'that nobody pressed anything');
    }

    /**
     * You already know: you pressed it.
     */
    public function test_pressing_execute_yourself_announces_nothing(): void
    {
        $result = (new SignalExecutor)->execute($this->signal());

        $this->assertTrue($result['ok'], $result['note']);

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'sendMessage'));
    }

    // =====================================================================
    // AND RECORDS IT REGARDLESS
    // =====================================================================

    /**
     * A notification outage must not become a record outage - the second is much worse.
     */
    public function test_the_order_is_recorded_even_when_telegram_is_unreachable(): void
    {
        Http::fake([
            'api.telegram.org/*' => Http::response(['description' => 'chat not found'], 400),
            'openrouter.ai/*' => Http::response($this->approval(), 200),
        ]);

        $this->artisan('telegram:execute')->assertSuccessful();

        $this->assertSame(1, TradeCommand::count(), 'the order still went out');
        $this->assertSame(1, BotLog::where('source', 'copier')->count(), 'and is still on /logs');
    }

    public function test_nothing_approved_is_a_quiet_success(): void
    {
        TelegramSignal::query()->delete();

        $this->artisan('telegram:execute')->assertSuccessful();

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'sendMessage'));
    }

    /**
     * Scheduling execution does not weaken any gate; the executor re-checks them all.
     */
    public function test_the_scheduled_path_still_honours_the_kill_switch(): void
    {
        $this->settings->update(['is_active' => false]);

        $this->artisan('telegram:execute')->assertSuccessful();

        $this->assertSame(0, TradeCommand::count());
        $this->assertSame(
            TelegramSignal::EXEC_BLOCKED,
            TelegramSignal::first()->fresh()->execution_status,
        );
    }

    // =====================================================================
    // THE REWARD FLOOR
    // =====================================================================

    /**
     * The fixture risks 5.00 to make 30.00 - six to one. Nothing about that changes when
     * no floor is configured, which is the default and the point.
     */
    public function test_a_generous_signal_is_taken_when_no_floor_is_set(): void
    {
        $this->artisan('telegram:execute')->assertSuccessful();

        $this->assertSame(1, TradeCommand::count());
    }

    public function test_a_signal_that_does_not_pay_for_its_stop_is_refused(): void
    {
        $this->settings->update(['min_reward_ratio' => 2.0]);

        // Entry 2650, stop 2645, target 2652: risking 5.00 to make 2.00.
        TelegramSignal::first()->update(['tp_prices' => [2652.0]]);

        $this->artisan('telegram:execute')->assertSuccessful();

        $signal = TelegramSignal::first()->fresh();

        $this->assertSame(0, TradeCommand::count(), 'A refused signal must not queue an order.');
        $this->assertSame(TelegramSignal::EXEC_BLOCKED, $signal->execution_status);
        $this->assertStringContainsString('Not worth the risk', (string) $signal->execution_note);
    }

    /**
     * The note has to name both numbers. "Blocked" on a card tells somebody nothing about
     * whether the floor is wrong or the signal was.
     */
    public function test_the_refusal_says_what_was_offered_against_what_was_required(): void
    {
        $this->settings->update(['min_reward_ratio' => 2.0]);
        TelegramSignal::first()->update(['tp_prices' => [2652.0]]);

        $this->artisan('telegram:execute')->assertSuccessful();

        $note = (string) TelegramSignal::first()->fresh()->execution_note;

        $this->assertStringContainsString('0.4', $note, 'what the signal offered');
        $this->assertStringContainsString('2', $note, 'what the account required');
    }

    /**
     * The same signal, unchanged, with the floor set below what it offers.
     */
    public function test_a_floor_it_clears_does_not_get_in_the_way(): void
    {
        $this->settings->update(['min_reward_ratio' => 2.0]);

        $this->artisan('telegram:execute')->assertSuccessful();

        $this->assertSame(1, TradeCommand::count());
        $this->assertSame(TelegramSignal::EXEC_QUEUED, TelegramSignal::first()->fresh()->execution_status);
    }

    // =====================================================================
    // HELPERS
    // =====================================================================

    /**
     * What the message renders as, not what goes down the wire.
     *
     * MarkdownV2 escaping turns "0.02" into "0\.02", so asserting on the raw payload would
     * be testing the escaper rather than whether the message says what it needs to.
     */
    private function announcementText(): string
    {
        $text = '';

        Http::assertSent(function ($request) use (&$text) {
            if (str_contains($request->url(), 'sendMessage')) {
                $text = $request['text'];

                return true;
            }

            return false;
        });

        return str_replace('\\', '', $text);
    }

    /**
     * @return array<string, mixed>
     */
    private function approval(): array
    {
        return [
            'model' => 'test-model',
            'choices' => [['message' => ['content' => json_encode([
                'approve' => true,
                'confidence' => 80,
                'reasoning' => 'Levels are coherent and the stop is intact.',
            ])]]],
        ];
    }

    private function fakeTelegramAndReviewer(): void
    {
        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true], 200),
            'openrouter.ai/*' => Http::response($this->approval(), 200),
        ]);

        $this->signal();
    }

    private function signal(): TelegramSignal
    {
        $channel = TelegramChannel::firstOrCreate(
            ['source' => TelegramChannel::SOURCE_ACCOUNT, 'chat_id' => '5001'],
            ['user_id' => $this->user->id, 'title' => 'Fira Smart Desk', 'is_enabled' => true],
        );

        return TelegramSignal::firstOrCreate(
            ['external_id' => 'tg:5001:1'],
            [
                'user_id' => $this->user->id,
                'source' => TelegramChannel::SOURCE_ACCOUNT,
                'chat_id' => '5001',
                'telegram_channel_id' => $channel->id,
                'chat_title' => 'Fira Smart Desk',
                'raw_text' => 'XAUUSD BUY 2650 SL 2645 TP 2680',
                'posted_at' => now()->subMinute(),
                'parse_status' => TelegramSignal::PARSE_OK,
                'symbol' => 'XAUUSD', 'direction' => 'buy',
                // A 5.00 stop: 50 pips at 0.10, so 10.00 of risk buys 0.02 lots.
                'entry_price' => 2650.0, 'sl_price' => 2645.0, 'tp_prices' => [2680.0],
                'review_status' => TelegramSignal::REVIEW_APPROVED,
                'review_confidence' => 80,
                'execution_status' => TelegramSignal::EXEC_NONE,
            ],
        );
    }
}
