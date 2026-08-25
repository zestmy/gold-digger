<?php

namespace Tests\Feature\Ai;

use App\Models\BotHeartbeat;
use App\Models\BotSettings;
use App\Models\BrokerAccount;
use App\Models\Candle;
use App\Models\Strategy;
use App\Models\SymbolSpec;
use App\Models\TelegramSignal;
use App\Models\User;
use App\Services\Ai\AutonomousTrader;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The system forming its own opinion.
 *
 * The design under test is the split: the model decides whether and which way, and never
 * what price. A language model asked for "a stop around 2643" produces a plausible number
 * with no relationship to this instrument's volatility, and a plausible wrong number sizes
 * a real position.
 */
class AutonomousTraderTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private BotSettings $settings;

    private BrokerAccount $account;

    private Strategy $strategy;

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
            'ai_trading_enabled' => true, 'ai_autonomous' => true,
            'ai_capital_cap' => 500.00, 'ai_risk_percentage' => 5.00,
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

        $this->trendingBars();
    }

    // =====================================================================
    // THE MODEL DECIDES DIRECTION, NOT PRICES
    // =====================================================================

    public function test_the_stop_comes_from_atr_and_not_from_the_model(): void
    {
        $this->decides(true, 'buy');

        $result = (new AutonomousTrader)->consider($this->user, 'XAUUSD');

        $this->assertTrue($result['traded'], $result['why']);

        $signal = $result['signal'];

        $this->assertNotNull($signal->sl_price);
        // Below the last close for a buy, and by a distance derived from measured
        // volatility rather than named by anybody.
        $this->assertLessThan((float) Candle::orderByDesc('open_time')->value('close'), (float) $signal->sl_price);
    }

    public function test_targets_are_multiples_of_that_stop(): void
    {
        $this->decides(true, 'buy');

        $signal = (new AutonomousTrader)->consider($this->user, 'XAUUSD')['signal'];

        $entry = (float) Candle::orderByDesc('open_time')->value('close');
        $stop = $entry - (float) $signal->sl_price;
        $targets = $signal->tp_prices;

        $this->assertCount(3, $targets);
        $this->assertEqualsWithDelta($entry + $stop, $targets[0], 0.01);
        $this->assertEqualsWithDelta($entry + 2 * $stop, $targets[1], 0.01);
        $this->assertEqualsWithDelta($entry + 3 * $stop, $targets[2], 0.01);
    }

    public function test_it_enters_at_market_rather_than_at_a_price_it_imagined(): void
    {
        $this->decides(true, 'buy');

        // The case was made about now. A resting order would be about a price nobody
        // argued for.
        $this->assertNull((new AutonomousTrader)->consider($this->user, 'XAUUSD')['signal']->entry_price);
    }

    // =====================================================================
    // REFUSING
    // =====================================================================

    public function test_saying_no_produces_no_signal(): void
    {
        $this->decides(false, null, 'Trend and momentum disagree.');

        $result = (new AutonomousTrader)->consider($this->user, 'XAUUSD');

        $this->assertFalse($result['traded']);
        $this->assertStringContainsString('disagree', $result['why']);
        $this->assertSame(0, TelegramSignal::count());
    }

    /**
     * The confluence floor is not the model's to lower.
     */
    public function test_a_direction_the_measurements_contradict_is_refused(): void
    {
        // A clean uptrend, and the model asks to sell it.
        $this->decides(true, 'sell');

        $result = (new AutonomousTrader)->consider($this->user, 'XAUUSD');

        $this->assertFalse($result['traded']);
        $this->assertStringContainsString('measured evidence', $result['why']);
        $this->assertSame(0, TelegramSignal::count());
    }

    public function test_asking_to_trade_without_a_direction_is_refused(): void
    {
        $this->decides(true, null);

        $this->assertFalse((new AutonomousTrader)->consider($this->user, 'XAUUSD')['traded']);
    }

    public function test_it_does_nothing_while_switched_off(): void
    {
        $this->settings->update(['ai_autonomous' => false]);
        $this->decides(true, 'buy');

        $result = (new AutonomousTrader)->consider($this->user, 'XAUUSD');

        $this->assertFalse($result['traded']);
        Http::assertNothingSent();
    }

    public function test_a_failed_model_call_trades_nothing(): void
    {
        Http::fake(['openrouter.ai/*' => Http::response([], 500)]);

        $result = (new AutonomousTrader)->consider($this->user, 'XAUUSD');

        // The one direction this must never fail in is "we could not decide, so we traded".
        $this->assertFalse($result['traded']);
        $this->assertSame(0, TelegramSignal::count());
    }

    // =====================================================================
    // ONE DECISION PER BAR
    // =====================================================================

    public function test_running_twice_on_the_same_bar_produces_one_signal(): void
    {
        $this->decides(true, 'buy');

        (new AutonomousTrader)->consider($this->user, 'XAUUSD');

        // The second call would otherwise open a second position on one idea.
        try {
            (new AutonomousTrader)->consider($this->user, 'XAUUSD');
        } catch (UniqueConstraintViolationException) {
            // The external_id unique index is the guard, and it holding is the point.
        }

        $this->assertSame(1, TelegramSignal::count());
    }

    // =====================================================================
    // HELPERS
    // =====================================================================

    private function decides(bool $trade, ?string $direction, string $reasoning = 'The structure supports it.'): void
    {
        Http::fake(['openrouter.ai/*' => Http::response([
            'model' => 'test-model',
            'choices' => [['message' => ['content' => json_encode([
                'trade' => $trade,
                'direction' => $direction,
                'reasoning' => $reasoning,
            ])]]],
        ], 200)]);
    }

    /**
     * A clean uptrend on both series, so the measurements have something to agree with.
     */
    private function trendingBars(): void
    {
        foreach (['M5', 'H1'] as $timeframe) {
            for ($i = 300; $i >= 0; $i--) {
                $base = 2500.0 + (300 - $i) * 0.5;

                Candle::create([
                    'user_id' => $this->user->id,
                    'broker_account_id' => $this->account->id,
                    'symbol' => 'XAUUSD',
                    'timeframe' => $timeframe,
                    'open_time' => now()->subMinutes(($timeframe === 'M5' ? 5 : 60) * $i),
                    'open' => $base - 0.4, 'high' => $base + 0.8,
                    'low' => $base - 0.9, 'close' => $base,
                ]);
            }
        }
    }
}
