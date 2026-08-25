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
use App\Services\Telegram\SignalExecutor;
use App\Services\Telegram\SignalPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Controls over how a copied signal reaches the broker.
 */
class ExecutionControlsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private BotSettings $settings;

    private BrokerAccount $account;

    private Strategy $strategy;

    private TelegramChannel $channel;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        config(['ai.key' => 'sk-or-test', 'ai.base_url' => 'https://openrouter.ai/api/v1']);

        $this->user = User::factory()->create();
        $this->strategy = Strategy::where('user_id', $this->user->id)->firstOrFail();

        $this->account = BrokerAccount::create([
            'user_id' => $this->user->id, 'label' => 'Demo', 'broker_name' => 'Elev8',
            'account_number' => '1', 'server' => 'Elev8-Demo2', 'is_demo' => true, 'is_active' => true,
        ]);

        $this->settings = BotSettings::where('user_id', $this->user->id)->firstOrFail();
        $this->settings->update([
            'is_active' => true, 'allowed_sessions' => null, 'news_filter_enabled' => false,
            'ai_trading_enabled' => true, 'ai_capital_cap' => 500.00,
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
            'volume_min' => 0.01, 'volume_step' => 0.01, 'digits' => 2, 'last_seen_at' => now(),
        ]);

        for ($i = 60; $i > 0; $i--) {
            Candle::create([
                'user_id' => $this->user->id, 'broker_account_id' => $this->account->id,
                'symbol' => 'XAUUSD', 'timeframe' => 'M5', 'open_time' => now()->subMinutes(5 * $i),
                'open' => 2649, 'high' => 2652, 'low' => 2648, 'close' => 2650,
                'spread_points' => 20,
            ]);
        }

        $this->channel = TelegramChannel::create([
            'user_id' => $this->user->id, 'source' => TelegramChannel::SOURCE_ACCOUNT,
            'chat_id' => '5001', 'title' => 'FTC 2026', 'is_enabled' => true,
        ]);

        Http::fake(['openrouter.ai/*' => Http::response([
            'model' => 'test', 'choices' => [['message' => ['content' => json_encode([
                'approve' => true, 'confidence' => 80, 'reasoning' => 'Fine.',
            ])]]],
        ], 200)]);
    }

    // =====================================================================
    // WHICH END OF AN ENTRY ZONE
    // =====================================================================

    public function test_the_default_asks_for_the_better_price(): void
    {
        $plan = app(SignalPlan::class)->for($this->signal(), $this->settings);

        $this->assertEqualsWithDelta(2645.0, $plan['entry'], 1e-9);
    }

    public function test_a_channel_can_ask_for_the_end_that_fills_soonest(): void
    {
        $this->channel->update(['entry_preference' => 'near']);

        $plan = app(SignalPlan::class)->for($this->signal(), $this->settings);

        $this->assertEqualsWithDelta(2648.0, $plan['entry'], 1e-9);
    }

    public function test_a_channel_can_ask_for_the_midpoint(): void
    {
        $this->channel->update(['entry_preference' => 'average']);

        $plan = app(SignalPlan::class)->for($this->signal(), $this->settings);

        $this->assertEqualsWithDelta(2646.5, $plan['entry'], 1e-9);
    }

    // =====================================================================
    // SPREAD IN THE STOP DISTANCE
    // =====================================================================

    /**
     * The buy fills at the ask and the stop is hit on the bid, so the published distance
     * overstates how far the market actually has to travel.
     */
    public function test_the_spread_makes_the_position_smaller_not_the_stop_wider(): void
    {
        $plain = $this->execute();

        TradeCommand::query()->delete();
        TelegramSignal::query()->delete();

        $this->settings->update(['copier_spread_buffer' => true]);
        $buffered = $this->execute();

        $this->assertLessThan($plain, $buffered, 'a wider distance has to size a smaller position');
    }

    // =====================================================================
    // CLOSING ON AN OPPOSITE SIGNAL
    // =====================================================================

    public function test_an_opposite_signal_closes_the_position_it_contradicts(): void
    {
        $this->settings->update(['copier_close_on_opposite' => true]);

        $held = $this->openPosition('buy');

        (new SignalExecutor)->execute($this->signal(direction: 'sell', sl: 2660.0));

        $close = TradeCommand::where('type', 'close')->firstOrFail();

        $this->assertSame($held->mt5_ticket, $close->payload['ticket']);
        $this->assertSame('opposite-signal', $close->payload['reason']);
    }

    public function test_a_position_in_the_same_direction_is_left_alone(): void
    {
        $this->settings->update(['copier_close_on_opposite' => true]);

        $this->openPosition('buy');

        (new SignalExecutor)->execute($this->signal());

        $this->assertSame(0, TradeCommand::where('type', 'close')->count());
    }

    /**
     * A hedge placed by hand, or one the strategy owns, was taken on a different view.
     */
    public function test_the_strategys_own_positions_are_not_closed(): void
    {
        $this->settings->update(['copier_close_on_opposite' => true]);

        $this->openPosition('buy', origin: 'bot');

        (new SignalExecutor)->execute($this->signal(direction: 'sell', sl: 2660.0));

        $this->assertSame(0, TradeCommand::where('type', 'close')->count());
    }

    public function test_nothing_is_closed_when_the_setting_is_off(): void
    {
        $this->openPosition('buy');

        (new SignalExecutor)->execute($this->signal(direction: 'sell', sl: 2660.0));

        $this->assertSame(0, TradeCommand::where('type', 'close')->count());
    }

    // =====================================================================
    // HELPERS
    // =====================================================================

    private function execute(): float
    {
        $result = (new SignalExecutor)->execute($this->signal());

        $this->assertTrue($result['ok'], $result['note']);

        $command = TradeCommand::whereIn('type', ['open', 'open_pending'])->firstOrFail();

        return (float) $command->payload['volume'];
    }

    private function openPosition(string $direction, string $origin = 'ai'): Trade
    {
        return Trade::create([
            'user_id' => $this->user->id,
            'strategy_id' => $this->strategy->id,
            'broker_account_id' => $this->account->id,
            'mt5_ticket' => 910077,
            'symbol' => 'XAUUSD', 'direction' => $direction,
            'initial_lot_size' => 0.05, 'remaining_lot_size' => 0.05,
            'entry_price' => 2650.0, 'sl_price' => 2645.0,
            'status' => 'open', 'origin' => $origin, 'opened_at' => now()->subHour(),
        ]);
    }

    private function signal(string $direction = 'buy', float $sl = 2640.0): TelegramSignal
    {
        return TelegramSignal::create([
            'user_id' => $this->user->id,
            'source' => TelegramChannel::SOURCE_ACCOUNT,
            'external_id' => 'tg:5001:'.random_int(1, 999999),
            'chat_id' => '5001',
            'telegram_channel_id' => $this->channel->id,
            'chat_title' => 'FTC 2026',
            'raw_text' => 'XAUUSD signal',
            'posted_at' => now()->subMinute(),
            'parse_status' => TelegramSignal::PARSE_OK,
            'symbol' => 'XAUUSD', 'direction' => $direction,
            // A zone: 2645 is the better price for a buy, 2648 the one nearer the market.
            'entry_price' => 2645.0, 'entry_zone_high' => 2648.0,
            'sl_price' => $sl, 'tp_prices' => [2680.0],
            'review_status' => TelegramSignal::REVIEW_APPROVED,
            'execution_status' => TelegramSignal::EXEC_NONE,
        ]);
    }
}
