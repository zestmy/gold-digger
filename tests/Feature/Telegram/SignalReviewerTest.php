<?php

namespace Tests\Feature\Telegram;

use App\Models\BotSettings;
use App\Models\BrokerAccount;
use App\Models\Candle;
use App\Models\Strategy;
use App\Models\TelegramSignal;
use App\Models\Trade;
use App\Models\User;
use App\Services\Telegram\SignalReviewer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Reviewing copied signals.
 *
 * The ordering is the design: every deterministic objection is checked in code before the
 * model is asked anything, so the model can only ever decline something the gates already
 * allowed. It is never in a position to approve something they blocked - an LLM that could
 * talk its way past a risk control would make every risk control here advisory.
 *
 * Most of these therefore assert that no HTTP call happens at all.
 */
class SignalReviewerTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = 'https://openrouter.ai/api/v1/chat/completions';

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
            'account_number' => '230070844', 'server' => 'Elev8-Demo2',
            'is_demo' => true, 'is_active' => true,
        ]);

        $this->settings = BotSettings::where('user_id', $this->user->id)->firstOrFail();
        $this->settings->update([
            'is_active' => true,
            'allowed_sessions' => null,
            'news_filter_enabled' => false,
            'ai_trading_enabled' => true,
            'ai_capital_cap' => 500.00,
            'ai_risk_percentage' => 1.00,
            'ai_max_concurrent_trades' => 1,
        ]);

        $this->seedBars();
    }

    private function seedBars(float $close = 2650.0): void
    {
        Candle::where('symbol', 'XAUUSD')->delete();

        for ($i = 300; $i > 0; $i--) {
            Candle::create([
                'user_id' => $this->user->id,
                'broker_account_id' => $this->account->id,
                'symbol' => 'XAUUSD',
                'timeframe' => 'M5',
                'open_time' => now()->subMinutes(5 * $i),
                'open' => $close - 1, 'high' => $close + 2, 'low' => $close - 2, 'close' => $close,
            ]);
        }
    }

    private function signal(array $overrides = []): TelegramSignal
    {
        return TelegramSignal::create($overrides + [
            'user_id' => $this->user->id,
            'source' => 'bot_api',
            'external_id' => 'bot:'.random_int(1, 999999),
            'chat_id' => '316745398',
            'raw_text' => 'XAUUSD BUY @ 2650 SL 2640 TP 2670',
            'posted_at' => now()->subMinutes(2),
            'parse_status' => TelegramSignal::PARSE_OK,
            'symbol' => 'XAUUSD',
            'direction' => 'buy',
            'entry_price' => 2650.0,
            'sl_price' => 2640.0,
            'tp_prices' => [2670.0],
            'review_status' => TelegramSignal::REVIEW_PENDING,
        ]);
    }

    private function verdict(bool $approve, string $why = 'Because.'): void
    {
        Http::fake([self::ENDPOINT => Http::response([
            'model' => 'anthropic/claude-sonnet-4.5',
            'choices' => [['message' => ['content' => json_encode([
                'approve' => $approve, 'confidence' => 70, 'reasoning' => $why,
            ])]]],
        ])]);
    }

    // =====================================================================
    // THE GATES RUN FIRST, AND THE MODEL NEVER SEES A BLOCKED SIGNAL
    // =====================================================================

    public function test_the_kill_switch_blocks_without_asking(): void
    {
        $this->settings->update(['is_active' => false]);
        Http::fake();

        $result = (new SignalReviewer)->review($this->signal());

        $this->assertSame(TelegramSignal::REVIEW_DECLINED, $result['status']);
        $this->assertStringContainsString('kill switch', $result['reasoning']);
        Http::assertNothingSent();
    }

    public function test_an_unfunded_ai_blocks_without_asking(): void
    {
        $this->settings->update(['ai_capital_cap' => null]);
        Http::fake();

        $result = (new SignalReviewer)->review($this->signal());

        $this->assertSame(TelegramSignal::REVIEW_DECLINED, $result['status']);
        Http::assertNothingSent();
    }

    public function test_an_exhausted_fund_blocks_without_asking(): void
    {
        Trade::create([
            'user_id' => $this->user->id, 'broker_account_id' => $this->account->id,
            'strategy_id' => Strategy::where('user_id', $this->user->id)->value('id'),
            'origin' => 'ai', 'symbol' => 'XAUUSD', 'direction' => 'buy',
            'initial_lot_size' => 0.01, 'remaining_lot_size' => 0.01, 'entry_price' => 2600,
            'status' => 'closed', 'net_pnl_money' => -500.00,
            'opened_at' => now()->subHour(), 'closed_at' => now(),
        ]);
        Http::fake();

        $this->assertSame(TelegramSignal::REVIEW_DECLINED, (new SignalReviewer)->review($this->signal())['status']);
        Http::assertNothingSent();
    }

    public function test_a_closed_session_blocks_without_asking(): void
    {
        $this->travelTo(Carbon::parse('2026-08-24 03:00:00', 'UTC'));
        $this->settings->update(['allowed_sessions' => ['london']]);
        Http::fake();

        $result = (new SignalReviewer)->review($this->signal(['posted_at' => now()->subMinute()]));

        $this->assertStringContainsString('session', $result['reasoning']);
        Http::assertNothingSent();
    }

    public function test_a_news_blackout_blocks_without_asking(): void
    {
        $this->settings->update(['news_filter_enabled' => true]);
        Http::fake();

        // No calendar loaded, so the filter cannot be checked - which fails closed.
        $result = (new SignalReviewer)->review($this->signal());

        $this->assertSame(TelegramSignal::REVIEW_DECLINED, $result['status']);
        Http::assertNothingSent();
    }

    public function test_a_stale_signal_blocks_without_asking(): void
    {
        Http::fake();

        $result = (new SignalReviewer)->review($this->signal([
            'posted_at' => now()->subMinutes(SignalReviewer::MAX_AGE_MINUTES + 10),
        ]));

        $this->assertStringContainsString('old', $result['reasoning']);
        Http::assertNothingSent();
    }

    /**
     * A modest retrace is exactly what a limit order is for.
     */
    public function test_a_resting_order_is_not_declined_just_for_price_being_away(): void
    {
        // Price 0.7 of a stop past the entry: a market fill would be a worse trade, but a
        // limit resting at 2650 is simply waiting, which is the point of placing one.
        $this->seedBars(2657.0);
        $this->verdict(true, 'Fine.');

        $this->assertSame(TelegramSignal::REVIEW_APPROVED, (new SignalReviewer)->review($this->signal())['status']);
    }

    /**
     * Resting has a limit of its own.
     */
    public function test_a_resting_order_price_has_left_far_behind_is_declined(): void
    {
        // Four stop distances past the entry. Waiting there is hoping the move reverses.
        $this->seedBars(2690.0);
        Http::fake();

        $result = (new SignalReviewer)->review($this->signal());

        $this->assertSame(TelegramSignal::REVIEW_DECLINED, $result['status']);
        $this->assertStringContainsString('already happened', $result['reasoning']);
        Http::assertNothingSent();
    }

    public function test_a_signal_already_past_its_stop_blocks_without_asking(): void
    {
        $this->seedBars(2635.0);
        Http::fake();

        $result = (new SignalReviewer)->review($this->signal());

        $this->assertStringContainsString('passed the signal', $result['reasoning']);
        Http::assertNothingSent();
    }

    // =====================================================================
    // WHAT THE MODEL IS ACTUALLY FOR
    // =====================================================================

    public function test_it_approves_when_the_model_makes_a_positive_case(): void
    {
        $this->verdict(true, 'Reward:risk is 2:1 and the signal runs with the trend.');

        $result = (new SignalReviewer)->review($this->signal());

        $this->assertSame(TelegramSignal::REVIEW_APPROVED, $result['status']);
        $this->assertSame(70, $result['confidence']);
        $this->assertStringContainsString('2:1', $result['reasoning']);
    }

    public function test_it_declines_when_the_model_declines(): void
    {
        $this->verdict(false, 'The stop is 0.3 x ATR and will be taken out by noise.');

        $this->assertSame(TelegramSignal::REVIEW_DECLINED, (new SignalReviewer)->review($this->signal())['status']);
    }

    /**
     * The one direction this must never fail in.
     */
    public function test_a_failed_review_declines_rather_than_defaulting_to_approve(): void
    {
        Http::fake([self::ENDPOINT => Http::response([], 500)]);

        $result = (new SignalReviewer)->review($this->signal());

        $this->assertSame(TelegramSignal::REVIEW_DECLINED, $result['status']);
        $this->assertStringContainsString('could not be completed', $result['reasoning']);
    }

    public function test_no_api_key_declines_rather_than_executing_unreviewed(): void
    {
        config(['ai.key' => null]);
        Http::fake();

        $result = (new SignalReviewer)->review($this->signal());

        $this->assertSame(TelegramSignal::REVIEW_DECLINED, $result['status']);
        $this->assertStringContainsString('unreviewed', $result['reasoning']);
        Http::assertNothingSent();
    }

    public function test_an_unparsed_message_is_never_reviewed(): void
    {
        Http::fake();

        $result = (new SignalReviewer)->review($this->signal([
            'parse_status' => TelegramSignal::PARSE_FAILED,
        ]));

        $this->assertSame(TelegramSignal::REVIEW_DECLINED, $result['status']);
        Http::assertNothingSent();
    }

    public function test_the_brief_carries_the_reward_to_risk_and_the_stop_against_atr(): void
    {
        // The two comparisons the reviewer is actually being asked to make.
        $this->verdict(true);

        (new SignalReviewer)->review($this->signal());

        Http::assertSent(function ($request) {
            $brief = $request->data()['messages'][1]['content'];

            return str_contains($brief, 'Reward:risk')
                && str_contains($brief, 'Stop vs ATR')
                && str_contains($brief, 'Absence of objection is not one');
        });
    }
}
