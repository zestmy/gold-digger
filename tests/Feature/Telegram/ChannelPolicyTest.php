<?php

namespace Tests\Feature\Telegram;

use App\Models\BotHeartbeat;
use App\Models\BotSettings;
use App\Models\BrokerAccount;
use App\Models\Candle;
use App\Models\SymbolSpec;
use App\Models\TelegramChannel;
use App\Models\TelegramSignal;
use App\Models\TradeCommand;
use App\Models\User;
use App\Services\Telegram\SignalExecutor;
use App\Services\Telegram\SignalPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Settings that belong to one provider rather than to the account.
 *
 * The property that matters most is the one that is easy to get wrong in the other
 * direction: null means inherit, resolved every read. A channel that copied the account's
 * settings when it was created would keep trading last month's risk after the account's
 * was lowered, with nothing on screen saying so.
 */
class ChannelPolicyTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private BotSettings $settings;

    private BrokerAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        config(['ai.key' => 'sk-or-test', 'ai.base_url' => 'https://openrouter.ai/api/v1']);

        $this->user = User::factory()->create();
        $this->account = BrokerAccount::create([
            'user_id' => $this->user->id, 'label' => 'Demo', 'broker_name' => 'Elev8',
            'account_number' => '1', 'server' => 'Elev8-Demo2', 'is_demo' => true, 'is_active' => true,
        ]);

        $this->settings = BotSettings::where('user_id', $this->user->id)->firstOrFail();
        $this->settings->update([
            'is_active' => true, 'allowed_sessions' => null, 'news_filter_enabled' => false,
            'ai_trading_enabled' => true, 'ai_capital_cap' => 500.00,
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
            'digits' => 2, 'last_seen_at' => now(),
        ]);

        for ($i = 60; $i > 0; $i--) {
            Candle::create([
                'user_id' => $this->user->id, 'broker_account_id' => $this->account->id,
                'symbol' => 'XAUUSD', 'timeframe' => 'M5', 'open_time' => now()->subMinutes(5 * $i),
                'open' => 2649, 'high' => 2652, 'low' => 2648, 'close' => 2650,
            ]);
        }

        Http::fake(['openrouter.ai/*' => Http::response([
            'model' => 'test-model',
            'choices' => [['message' => ['content' => json_encode([
                'approve' => true, 'confidence' => 80, 'reasoning' => 'Fine.',
            ])]]],
        ], 200)]);
    }

    // =====================================================================
    // INHERIT, NOT COPY
    // =====================================================================

    public function test_an_unset_override_follows_the_account(): void
    {
        $channel = $this->channel();

        $this->assertSame(5.0, $channel->policy($this->settings)['risk_percentage']);

        $this->settings->update(['ai_risk_percentage' => 2.00]);

        // The whole point: lowering risk globally lowers it everywhere that has not
        // deliberately said otherwise.
        $this->assertSame(2.0, $channel->policy($this->settings->fresh())['risk_percentage']);
    }

    public function test_an_override_survives_the_account_changing(): void
    {
        $channel = $this->channel(['risk_percentage' => 1.00]);

        $this->settings->update(['ai_risk_percentage' => 9.00]);

        $this->assertSame(1.0, $channel->policy($this->settings->fresh())['risk_percentage']);
        $this->assertContains('risk_percentage', $channel->policy($this->settings->fresh())['overridden']);
    }

    // =====================================================================
    // RISK
    // =====================================================================

    public function test_a_channel_can_be_traded_at_reduced_risk(): void
    {
        // 5% of a 500 fund is 25, over a 50-pip stop at 10 a pip: 0.05 lots.
        // 1% is 5, which buys 0.01.
        $signal = $this->signal($this->channel(['risk_percentage' => 1.00]));

        $result = (new SignalExecutor)->execute($signal);

        $this->assertTrue($result['ok'], $result['note']);
        $this->assertEqualsWithDelta(0.01, TradeCommand::firstOrFail()->payload['volume'], 1e-9);
    }

    public function test_the_fund_cap_still_bounds_the_total_whatever_a_channel_asks_for(): void
    {
        // An override changes the share, not the thing it is a share of.
        $signal = $this->signal($this->channel(['risk_percentage' => 50.00]));

        (new SignalExecutor)->execute($signal);

        // 50% of 500 is 250 over a 50-pip stop = 0.5 lots. Sized from the fund, so the
        // cap is still the ceiling on what can ever be lost.
        $this->assertEqualsWithDelta(0.5, TradeCommand::firstOrFail()->payload['volume'], 1e-9);
    }

    // =====================================================================
    // LEVELS
    // =====================================================================

    /**
     * Whether to trust a provider's stops is a judgement about that provider.
     */
    public function test_a_channel_can_use_its_own_levels_while_the_account_uses_the_strategys(): void
    {
        $this->settings->update(['copier_levels' => 'strategy']);

        $signal = $this->signal($this->channel(['copier_levels' => 'provider']));

        $plan = app(SignalPlan::class)->for($signal->fresh(), $this->settings->fresh());

        $this->assertSame(SignalPlan::SOURCE_PROVIDER, $plan['source']);
        $this->assertEqualsWithDelta(2645.0, $plan['sl'], 1e-9);
    }

    // =====================================================================
    // SYMBOLS
    // =====================================================================

    public function test_an_allow_list_takes_only_what_it_names(): void
    {
        $channel = $this->channel(['symbols_allow' => ['XAUUSD']]);

        $this->assertTrue($channel->allowsSymbol('XAUUSD'));
        $this->assertFalse($channel->allowsSymbol('US30'));
    }

    public function test_a_deny_list_takes_everything_else(): void
    {
        $channel = $this->channel(['symbols_deny' => ['US30']]);

        $this->assertTrue($channel->allowsSymbol('XAUUSD'));
        $this->assertFalse($channel->allowsSymbol('US30'));
    }

    /**
     * Two settings that quietly contradict each other would be worse than one.
     */
    public function test_an_allow_list_wins_outright_over_a_deny_list(): void
    {
        $channel = $this->channel([
            'symbols_allow' => ['XAUUSD'],
            'symbols_deny' => ['XAUUSD'],
        ]);

        $this->assertTrue($channel->allowsSymbol('XAUUSD'), '"only gold" has to mean only gold');
        $this->assertFalse($channel->allowsSymbol('EURUSD'));
    }

    public function test_a_blocked_instrument_is_refused_before_the_model_is_asked(): void
    {
        $signal = $this->signal($this->channel(['symbols_allow' => ['EURUSD']]));

        $result = (new SignalExecutor)->execute($signal);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('not an instrument this channel is allowed', $result['note']);
        $this->assertSame(0, TradeCommand::count());
    }

    // =====================================================================
    // HELPERS
    // =====================================================================

    private function channel(array $attributes = []): TelegramChannel
    {
        return TelegramChannel::create($attributes + [
            'user_id' => $this->user->id,
            'source' => TelegramChannel::SOURCE_ACCOUNT,
            'chat_id' => '5001',
            'title' => 'FTC 2026',
            'is_enabled' => true,
        ]);
    }

    private function signal(TelegramChannel $channel): TelegramSignal
    {
        return TelegramSignal::create([
            'user_id' => $this->user->id,
            'source' => TelegramChannel::SOURCE_ACCOUNT,
            'external_id' => 'tg:5001:1',
            'chat_id' => '5001',
            'telegram_channel_id' => $channel->id,
            'chat_title' => $channel->title,
            'raw_text' => 'XAUUSD BUY 2650 SL 2645 TP 2680',
            'posted_at' => now()->subMinute(),
            'parse_status' => TelegramSignal::PARSE_OK,
            'symbol' => 'XAUUSD', 'direction' => 'buy',
            'entry_price' => 2650.0, 'sl_price' => 2645.0, 'tp_prices' => [2680.0],
            'review_status' => TelegramSignal::REVIEW_APPROVED,
            'execution_status' => TelegramSignal::EXEC_NONE,
        ]);
    }
}
