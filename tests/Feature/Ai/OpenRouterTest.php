<?php

namespace Tests\Feature\Ai;

use App\Models\Strategy;
use App\Models\User;
use App\Services\Ai\OpenRouter;
use App\Services\Ai\PairAnalysis;
use App\Services\Ai\PairAnalyst;
use App\Services\Ai\StrategyProposer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The AI surfaces.
 *
 * What matters here is not that a model can write a sentence - it is that a model which
 * misbehaves cannot damage anything. A refusal, a prose answer where JSON was asked for, a
 * dead key, or a proposal that would produce an impossible strategy all have to fail
 * visibly and leave the dashboard working.
 */
class OpenRouterTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = 'https://openrouter.ai/api/v1/chat/completions';

    protected function setUp(): void
    {
        parent::setUp();
        config(['ai.key' => 'sk-or-test', 'ai.base_url' => 'https://openrouter.ai/api/v1']);
    }

    private function reply(array $payload, int $status = 200): void
    {
        Http::fake([self::ENDPOINT => Http::response([
            'model' => 'anthropic/claude-sonnet-4.5',
            'choices' => [['message' => ['content' => json_encode($payload)]]],
        ], $status)]);
    }

    private function context(): array
    {
        return [
            'warm' => true, 'symbol' => 'XAUUSD', 'trend_timeframe' => 'H1', 'entry_timeframe' => 'M5',
            'trend' => 'buy', 'entry_bias' => 'buy', 'aligned' => true,
            'ema_fast' => 2000.0, 'ema_slow' => 1990.0, 'ema_gap_pct' => 0.5,
            'adx' => 16.9, 'adx_label' => 'ranging', 'plus_di' => 24.0, 'minus_di' => 19.0,
            'atr' => 3.88, 'atr_pct' => 0.08, 'last_close' => 4651.12,
            'last_bar_at' => now(), 'bars_entry' => 300, 'bars_trend' => 300,
        ];
    }

    // =====================================================================
    // TRANSPORT
    // =====================================================================

    public function test_it_returns_the_validated_object(): void
    {
        $this->reply(['passes' => true, 'why' => 'ADX cleared the threshold.']);

        $result = (new OpenRouter)->structured('m', 'sys', 'brief', 'probe', ['type' => 'object']);

        $this->assertTrue($result['ok']);
        $this->assertSame('ADX cleared the threshold.', $result['data']['why']);
        $this->assertSame('anthropic/claude-sonnet-4.5', $result['model']);
    }

    public function test_it_asks_for_a_strict_schema(): void
    {
        // Without strict json_schema the model answers in prose and the caller ends up
        // parsing English, which fails in ways that look like unhelpfulness, not a bug.
        $this->reply(['ok' => true]);

        (new OpenRouter)->structured('m', 'sys', 'brief', 'my_schema', ['type' => 'object']);

        Http::assertSent(function ($request) {
            $body = $request->data();

            return $body['response_format']['type'] === 'json_schema'
                && $body['response_format']['json_schema']['strict'] === true
                && $body['response_format']['json_schema']['name'] === 'my_schema';
        });
    }

    public function test_prose_instead_of_json_is_an_error_not_a_crash(): void
    {
        Http::fake([self::ENDPOINT => Http::response([
            'choices' => [['message' => ['content' => 'Sure! Gold looks bullish today.']]],
        ], 200)]);

        $result = (new OpenRouter)->structured('m', 'sys', 'brief', 'probe', ['type' => 'object']);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('did not return JSON', $result['error']);
    }

    /**
     * Separate tests, not one: Http::fake() appends stubs and the first match wins, so a
     * second fake for the same URL in one test is never reached.
     */
    public function test_a_rejected_key_says_so(): void
    {
        Http::fake([self::ENDPOINT => Http::response([], 401)]);

        $this->assertStringContainsString('key', (new OpenRouter)->structured('m', 's', 'b', 'p', [])['error']);
    }

    public function test_running_out_of_credit_is_reported_separately_from_a_bad_key(): void
    {
        // Same consequence, completely different remedy - one is a billing page, the
        // other is a settings page.
        Http::fake([self::ENDPOINT => Http::response([], 402)]);

        $this->assertStringContainsString('credit', (new OpenRouter)->structured('m', 's', 'b', 'p', [])['error']);
    }

    public function test_it_is_off_without_a_key(): void
    {
        config(['ai.key' => null]);
        Http::fake();

        $this->assertFalse((new OpenRouter)->configured());
        $this->assertFalse((new OpenRouter)->structured('m', 's', 'b', 'p', [])['ok']);
        Http::assertNothingSent();
    }

    // =====================================================================
    // THE ANALYST
    // =====================================================================

    public function test_the_analyst_keeps_reading_and_outlook_separate(): void
    {
        $this->reply([
            'headline' => 'Trend up but too weak to trade.',
            'reading' => 'H1 and M5 both point up, but ADX at 16.9 is under the threshold of 25.',
            'outlook' => 'If ADX firms above 25 while alignment holds, a setup could qualify.',
        ]);

        $result = (new PairAnalyst)->analyse($this->context(), ['trading_enabled' => true]);

        $this->assertTrue($result['ok']);
        $this->assertInstanceOf(PairAnalysis::class, $result['analysis']);
        $this->assertStringContainsString('16.9', $result['analysis']->reading);
        $this->assertNotSame($result['analysis']->reading, $result['analysis']->outlook);
    }

    public function test_the_analyst_refuses_to_describe_a_cold_series(): void
    {
        // Asking anyway produces a confident paragraph about an empty series.
        Http::fake();

        $result = (new PairAnalyst)->analyse(['warm' => false], []);

        $this->assertFalse($result['ok']);
        Http::assertNothingSent();
    }

    public function test_a_missing_section_is_rejected_rather_than_half_shown(): void
    {
        $this->reply(['headline' => 'Something', 'reading' => 'Something else', 'outlook' => '']);

        $result = (new PairAnalyst)->analyse($this->context(), []);

        $this->assertFalse($result['ok']);
        $this->assertNull($result['analysis']);
    }

    // =====================================================================
    // THE PROPOSER
    // =====================================================================

    private function strategy(): Strategy
    {
        $user = User::factory()->create();
        $strategy = Strategy::where('user_id', $user->id)->firstOrFail();
        $strategy->update(['ema_fast' => 20, 'ema_slow' => 50, 'tp1_pips' => 30, 'tp2_pips' => 100, 'tp3_pips' => 200]);

        return $strategy;
    }

    public function test_it_returns_sweepable_proposals(): void
    {
        $this->reply(['proposals' => [
            ['rationale' => 'Loosen the ADX gate.', 'parameters' => ['adx_threshold' => 18, 'ema_fast' => null]],
        ]]);

        $result = (new StrategyProposer)->propose($this->strategy(), []);

        $this->assertTrue($result['ok']);
        $this->assertSame(['adx_threshold' => 18.0], $result['proposals'][0]['parameters']);
        $this->assertSame('Loosen the ADX gate.', $result['proposals'][0]['rationale']);
    }

    /**
     * A strategy that cannot exist must never reach the backtester.
     */
    public function test_an_inverted_ema_pair_is_discarded(): void
    {
        $this->reply(['proposals' => [
            ['rationale' => 'Fast above slow.', 'parameters' => ['ema_fast' => 60]],
            ['rationale' => 'Sensible.', 'parameters' => ['adx_threshold' => 22]],
        ]]);

        $result = (new StrategyProposer)->propose($this->strategy(), []);

        $this->assertTrue($result['ok']);
        $this->assertCount(1, $result['proposals'], 'ema_fast 60 against ema_slow 50 is not a strategy.');
        $this->assertSame(['adx_threshold' => 22.0], $result['proposals'][0]['parameters']);
    }

    public function test_a_backwards_ladder_is_discarded(): void
    {
        $this->reply(['proposals' => [
            ['rationale' => 'TP1 beyond TP2.', 'parameters' => ['tp1_pips' => 150]],
        ]]);

        $this->assertFalse((new StrategyProposer)->propose($this->strategy(), [])['ok']);
    }

    public function test_columns_that_cannot_be_swept_are_dropped(): void
    {
        // A model inventing `symbol` or `is_active` must not be able to rewrite anything
        // structural through a parameter set.
        $this->reply(['proposals' => [
            ['rationale' => 'Changing the instrument.', 'parameters' => ['symbol' => 1, 'is_active' => 0, 'adx_threshold' => 21]],
        ]]);

        $result = (new StrategyProposer)->propose($this->strategy(), []);

        $this->assertSame(['adx_threshold' => 21.0], $result['proposals'][0]['parameters']);
    }

    public function test_negative_and_absurd_values_are_discarded(): void
    {
        $this->reply(['proposals' => [
            ['rationale' => 'Negative stop.', 'parameters' => ['sl_atr_multiplier' => -1]],
            ['rationale' => 'One-bar EMA.', 'parameters' => ['ema_fast' => 1]],
        ]]);

        $this->assertFalse((new StrategyProposer)->propose($this->strategy(), [])['ok']);
    }
}
