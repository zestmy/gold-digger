<?php

namespace Tests\Feature\Analysis;

use App\Models\AiUsage;
use App\Models\BotHeartbeat;
use App\Models\BotToken;
use App\Models\BrokerAccount;
use App\Models\Candle;
use App\Models\ChartAnalysis;
use App\Models\Strategy;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The chart analysis API.
 *
 * Two properties carry most of the weight. One tenant's token must not reach another's
 * readings - the authorising is done by the tenant scope rather than by a check in each
 * action, so it is worth proving rather than assuming. And `quick` must cost nothing: it
 * exists so a client can poll structure without buying a paragraph every few seconds, and
 * an endpoint that quietly spent the daily allowance would be worse than not having it.
 */
class ChartAnalysisApiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private BrokerAccount $account;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        config(['ai.key' => 'sk-or-test', 'ai.base_url' => 'https://openrouter.ai/api/v1']);

        $this->user = User::factory()->create();

        $this->account = BrokerAccount::create([
            'user_id' => $this->user->id, 'label' => 'Demo', 'broker_name' => 'Elev8',
            'account_number' => '1', 'server' => 'Elev8-Demo', 'is_demo' => true, 'is_active' => true,
        ]);

        BotHeartbeat::create([
            'user_id' => $this->user->id, 'broker_account_id' => $this->account->id,
            'source' => 'mql5_ea', 'algo_trading_enabled' => true, 'broker_connected' => true,
            'resolved_symbol' => 'XAUUSD', 'last_seen_at' => now(),
        ]);

        Strategy::where('user_id', $this->user->id)->firstOrFail()
            ->update(['timeframe_entry' => 'M5', 'timeframe_trend' => 'H1']);

        [$this->token] = BotToken::generate($this->user, 'A client');

        $this->bars();
    }

    // =====================================================================
    // AUTHENTICATION AND ISOLATION
    // =====================================================================

    public function test_every_endpoint_refuses_an_unauthenticated_caller(): void
    {
        $this->getJson('/api/v1/analysis/symbols')->assertUnauthorized();
        $this->getJson('/api/v1/analysis/candles?symbol=XAUUSD&timeframe=M5')->assertUnauthorized();
        $this->postJson('/api/v1/analysis/quick', ['symbol' => 'XAUUSD'])->assertUnauthorized();
        $this->postJson('/api/v1/analysis', ['symbol' => 'XAUUSD'])->assertUnauthorized();
    }

    /**
     * Not refused - not found. The same answer an id that never existed gets, which is what
     * stops this confirming that somebody else's analysis exists.
     */
    public function test_a_token_cannot_read_another_tenants_analysis(): void
    {
        $stranger = User::factory()->create();

        $theirs = ChartAnalysis::query()->forceCreate([
            'user_id' => $stranger->id, 'symbol' => 'XAUUSD', 'timeframe' => 'M5',
            'bar_open_time' => now(), 'bias' => 'bullish', 'plan' => 'buy',
            'headline' => 'Private.', 'structure' => 's', 'reasoning' => 'r', 'invalidation' => 'i',
        ]);

        $this->withToken($this->token)
            ->getJson("/api/v1/analysis/{$theirs->id}")
            ->assertNotFound();
    }

    public function test_a_token_reads_its_own_analysis(): void
    {
        $mine = ChartAnalysis::query()->forceCreate([
            'user_id' => $this->user->id, 'symbol' => 'XAUUSD', 'timeframe' => 'M5',
            'bar_open_time' => now(), 'bias' => 'bullish', 'plan' => 'wait',
            'headline' => 'Nothing worth taking.', 'structure' => 's', 'reasoning' => 'r', 'invalidation' => 'i',
        ]);

        $this->withToken($this->token)
            ->getJson("/api/v1/analysis/{$mine->id}")
            ->assertOk()
            ->assertJsonPath('analysis.headline', 'Nothing worth taking.')
            // A refusal reports null prices rather than plausible ones.
            ->assertJsonPath('analysis.entry', null)
            ->assertJsonPath('analysis.complete', false);
    }

    // =====================================================================
    // THE MEASURED HALF COSTS NOTHING
    // =====================================================================

    public function test_quick_analysis_never_calls_a_model(): void
    {
        Http::fake();

        $this->withToken($this->token)
            ->postJson('/api/v1/analysis/quick', ['symbol' => 'XAUUSD', 'timeframe' => 'M5'])
            ->assertOk()
            ->assertJsonStructure(['symbol', 'timeframe', 'structure', 'levels', 'events', 'setups', 'readings']);

        Http::assertNothingSent();
        $this->assertSame(0, AiUsage::acrossTenants()->count(), 'the measured half must not touch the allowance');
    }

    public function test_quick_analysis_works_with_no_api_key_at_all(): void
    {
        config(['ai.key' => '']);

        $this->withToken($this->token)
            ->postJson('/api/v1/analysis/quick', ['symbol' => 'XAUUSD', 'timeframe' => 'M5'])
            ->assertOk()
            ->assertJsonPath('symbol', 'XAUUSD');
    }

    /**
     * An empty list is the common answer and means no pattern meets enough of its own
     * definition. It is not padded to look like a result.
     */
    public function test_quick_analysis_returns_setup_candidates_as_a_list(): void
    {
        $response = $this->withToken($this->token)
            ->postJson('/api/v1/analysis/quick', ['symbol' => 'XAUUSD', 'timeframe' => 'M5'])
            ->assertOk();

        $this->assertIsArray($response->json('setups'));
    }

    // =====================================================================
    // THE HALF THAT DOES
    // =====================================================================

    public function test_a_full_analysis_returns_the_reading_and_stores_it(): void
    {
        $this->modelReplies();

        $this->withToken($this->token)
            ->postJson('/api/v1/analysis', ['symbol' => 'XAUUSD', 'timeframe' => 'M5'])
            ->assertOk()
            ->assertJsonPath('reading.plan', 'wait')
            ->assertJsonPath('analysis.symbol', 'XAUUSD');

        $this->assertSame(1, ChartAnalysis::acrossTenants()->count());
        $this->assertSame($this->user->id, ChartAnalysis::acrossTenants()->sole()->user_id);
    }

    public function test_a_full_analysis_is_metered_against_the_tenants_allowance(): void
    {
        $this->modelReplies();

        $this->withToken($this->token)
            ->postJson('/api/v1/analysis', ['symbol' => 'XAUUSD', 'timeframe' => 'M5'])
            ->assertOk();

        $usage = AiUsage::acrossTenants()->sole();

        $this->assertSame($this->user->id, $usage->user_id);
        $this->assertSame('chart_analyst', $usage->call_site);
    }

    /**
     * An exhausted allowance degrades the response rather than emptying it - the measured
     * half was free and is still worth returning.
     */
    public function test_an_exhausted_allowance_still_returns_the_measured_half(): void
    {
        config(['ai.limits.daily_calls' => 0]);
        Http::fake();

        $response = $this->withToken($this->token)
            ->postJson('/api/v1/analysis', ['symbol' => 'XAUUSD', 'timeframe' => 'M5'])
            ->assertOk();

        $this->assertNull($response->json('reading'));
        $this->assertNotEmpty($response->json('levels'));
        $this->assertStringContainsString('allowance', (string) $response->json('error'));
    }

    // =====================================================================
    // CANDLES AND SYMBOLS
    // =====================================================================

    public function test_candles_come_back_oldest_first_with_epoch_seconds(): void
    {
        $response = $this->withToken($this->token)
            ->getJson('/api/v1/analysis/candles?symbol=XAUUSD&timeframe=M5&limit=50')
            ->assertOk()
            ->assertJsonPath('count', 50);

        $times = array_column($response->json('candles'), 'timestamp');
        $sorted = $times;
        sort($sorted);

        $this->assertSame($sorted, $times, 'a reversed series silently computes an EMA of the future');
        $this->assertIsInt($times[0]);
    }

    public function test_candles_reject_a_timeframe_that_is_not_supported(): void
    {
        $this->withToken($this->token)
            ->getJson('/api/v1/analysis/candles?symbol=XAUUSD&timeframe=M7')
            ->assertStatus(422)
            ->assertJsonValidationErrors('timeframe');
    }

    public function test_candles_require_a_symbol(): void
    {
        $this->withToken($this->token)
            ->getJson('/api/v1/analysis/candles?timeframe=M5')
            ->assertStatus(422)
            ->assertJsonValidationErrors('symbol');
    }

    public function test_symbols_reports_what_is_available_and_what_it_would_cost(): void
    {
        $this->withToken($this->token)
            ->getJson('/api/v1/analysis/symbols')
            ->assertOk()
            ->assertJsonStructure([
                'symbols', 'timeframes', 'broker_account_id',
                'ai' => ['available', 'allowance' => ['limit', 'used', 'remaining', 'exhausted']],
            ]);
    }

    public function test_an_instrument_with_no_stored_bars_is_refused_rather_than_invented(): void
    {
        $this->withToken($this->token)
            ->postJson('/api/v1/analysis/quick', ['symbol' => 'EURUSD', 'timeframe' => 'M5'])
            ->assertStatus(422)
            ->assertJsonStructure(['message']);
    }

    // =====================================================================
    // FIXTURES
    // =====================================================================

    /**
     * Both series the strategy names. `MarketContext` reads the entry and trend timeframes
     * the strategy is configured with, not the one the request asked about, so seeding only
     * one leaves it cold and every reading refuses.
     */
    private function bars(): void
    {
        $rows = [];

        foreach (['M5' => 5, 'H1' => 60] as $timeframe => $minutes) {
            for ($i = 300; $i >= 0; $i--) {
                $base = 2650.0 + sin($i / 4.0) * 8.0;

                $rows[] = [
                    'user_id' => $this->user->id,
                    'broker_account_id' => $this->account->id,
                    'symbol' => 'XAUUSD', 'timeframe' => $timeframe,
                    'open_time' => now()->subMinutes($minutes * $i),
                    'open' => $base - 0.5, 'high' => $base + 1.5,
                    'low' => $base - 1.5, 'close' => $base,
                    'tick_volume' => 100, 'source' => 'mql5_ea',
                    'created_at' => now(), 'updated_at' => now(),
                ];
            }
        }

        Candle::insert($rows);
    }

    private function modelReplies(): void
    {
        Http::fake(['openrouter.ai/*' => Http::response([
            'model' => 'test-model',
            'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 50],
            'choices' => [['message' => ['content' => json_encode([
                'headline' => 'Range-bound above support.',
                'structure' => 'Two clear tests of the lower level.',
                'bias' => 'neutral',
                'plan' => 'wait',
                'setup_type' => null,
                'entry_level' => null,
                'stop_level' => null,
                'target_level' => null,
                'reasoning' => 'Mixed structure.',
                'invalidation' => 'A close below the support.',
            ])]]],
        ], 200)]);
    }
}
