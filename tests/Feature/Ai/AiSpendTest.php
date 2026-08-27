<?php

namespace Tests\Feature\Ai;

use App\Livewire\Pages\Settings;
use App\Models\AiUsage;
use App\Models\BotSettings;
use App\Models\User;
use App\Services\Ai\AiSpend;
use App\Services\Ai\OpenRouter;
use App\Support\Tenancy\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * AI spend, metered per tenant.
 *
 * Nine call sites shared one platform API key and nothing recorded who spent what, which
 * as a product is unbounded cost of goods with no attribution. These tests fix the two
 * properties that make it a business rather than a bill: every attempt is counted against
 * the tenant who caused it, and a tenant who has used their day cannot cause another.
 *
 * The failure cases carry as much weight as the success case here. A meter that only
 * counted successful calls would under-report worst on the days something was broken -
 * which is exactly when somebody goes looking at it.
 */
class AiSpendTest extends TestCase
{
    use RefreshDatabase;

    private User $alice;

    private User $bob;

    protected function setUp(): void
    {
        parent::setUp();

        config(['ai.key' => 'sk-or-test', 'ai.base_url' => 'https://openrouter.ai/api/v1']);

        $this->alice = User::factory()->create();
        $this->bob = User::factory()->create();
    }

    // =========================================================================
    // RECORDING
    // =========================================================================

    public function test_a_successful_call_is_recorded_against_the_current_tenant(): void
    {
        $this->fakeAnswer(usage: ['prompt_tokens' => 1200, 'completion_tokens' => 300, 'cost' => 0.004215]);

        Tenant::for($this->alice, fn () => $this->ask());

        $row = AiUsage::acrossTenants()->sole();

        $this->assertSame($this->alice->id, $row->user_id);
        $this->assertSame('chart_analyst', $row->call_site);
        $this->assertTrue($row->ok);
        $this->assertSame(1200, $row->prompt_tokens);
        $this->assertSame(300, $row->completion_tokens);
        $this->assertSame('0.004215', $row->cost_usd);
    }

    /**
     * OpenRouter bills per request that reaches a model, so a 5xx that arrived after
     * generation is a charge. `OpenRouter` already refuses to retry status codes for that
     * reason; this is the other half of the same argument.
     */
    public function test_a_server_error_is_still_counted_because_it_was_still_billed(): void
    {
        Http::fake(['openrouter.ai/*' => Http::response(['error' => ['message' => 'upstream exploded']], 500)]);

        $result = Tenant::for($this->alice, fn () => $this->ask());

        $this->assertFalse($result['ok']);

        $row = AiUsage::acrossTenants()->sole();
        $this->assertFalse($row->ok);
        $this->assertStringContainsString('500', (string) $row->failure);
    }

    public function test_prose_instead_of_json_is_counted_because_the_model_still_wrote_it(): void
    {
        Http::fake(['openrouter.ai/*' => Http::response([
            'model' => 'anthropic/claude-sonnet-5',
            'choices' => [['message' => ['content' => 'Well, it depends on the market.']]],
        ], 200)]);

        Tenant::for($this->alice, fn () => $this->ask());

        $row = AiUsage::acrossTenants()->sole();

        $this->assertFalse($row->ok);
        $this->assertSame('response was not JSON', $row->failure);
    }

    /**
     * A rejected key never reached a model. Counting it would make a misconfiguration
     * consume an allowance that nothing was spent from.
     */
    public function test_a_rejected_key_is_not_counted_because_nothing_was_billed(): void
    {
        Http::fake(['openrouter.ai/*' => Http::response(['error' => ['message' => 'no']], 401)]);

        Tenant::for($this->alice, fn () => $this->ask());

        $this->assertSame(0, AiUsage::acrossTenants()->count());
    }

    public function test_running_out_of_credit_is_not_counted_either(): void
    {
        Http::fake(['openrouter.ai/*' => Http::response(['error' => ['message' => 'skint']], 402)]);

        Tenant::for($this->alice, fn () => $this->ask());

        $this->assertSame(0, AiUsage::acrossTenants()->count());
    }

    public function test_the_model_that_actually_served_the_call_is_recorded(): void
    {
        $this->fakeAnswer(served: 'anthropic/claude-opus-5');

        Tenant::for($this->alice, fn () => $this->ask('anthropic/claude-sonnet-5'));

        $row = AiUsage::acrossTenants()->sole();

        $this->assertSame('anthropic/claude-sonnet-5', $row->model_requested);
        $this->assertSame('anthropic/claude-opus-5', $row->model_served);
    }

    public function test_a_gateway_that_returns_no_cost_still_records_the_call(): void
    {
        // Cost depends on the gateway volunteering a figure. Losing the row because it did
        // not would defeat the point of having the table.
        $this->fakeAnswer(usage: ['prompt_tokens' => 10, 'completion_tokens' => 5]);

        Tenant::for($this->alice, fn () => $this->ask());

        $row = AiUsage::acrossTenants()->sole();

        $this->assertNull($row->cost_usd);
        $this->assertSame(10, $row->prompt_tokens);
    }

    // =========================================================================
    // THE ALLOWANCE
    // =========================================================================

    public function test_a_tenant_who_has_used_their_day_is_refused_before_anything_is_sent(): void
    {
        config(['ai.limits.daily_calls' => 2]);
        $this->fakeAnswer();

        Tenant::for($this->alice, function () {
            $this->ask();
            $this->ask();
            $result = $this->ask();

            $this->assertFalse($result['ok']);
            $this->assertStringContainsString('allowance', (string) $result['error']);
        });

        // Two sent, and the third never left the building.
        Http::assertSentCount(2);
        $this->assertSame(2, AiUsage::acrossTenants()->count());
    }

    /**
     * A refusal is not consumption. If it were, the allowance would keep shrinking every
     * time it was enforced and could never recover within the day.
     */
    public function test_being_refused_does_not_itself_count_against_the_allowance(): void
    {
        config(['ai.limits.daily_calls' => 1]);
        $this->fakeAnswer();

        Tenant::for($this->alice, function () {
            $this->ask();
            $this->ask();
            $this->ask();
        });

        $this->assertSame(1, AiUsage::acrossTenants()->count());
        $this->assertSame(1, app(AiSpend::class)->allowance($this->alice->id)['used']);
    }

    public function test_one_tenant_cannot_spend_anothers_allowance(): void
    {
        config(['ai.limits.daily_calls' => 1]);
        $this->fakeAnswer();

        Tenant::for($this->alice, fn () => $this->ask());

        $result = Tenant::for($this->bob, fn () => $this->ask());

        $this->assertTrue($result['ok'], 'Alice using her day must not close Bob\'s.');
        $this->assertSame(1, AiUsage::acrossTenants()->where('user_id', $this->alice->id)->count());
        $this->assertSame(1, AiUsage::acrossTenants()->where('user_id', $this->bob->id)->count());
    }

    public function test_a_tenants_own_limit_overrides_the_platform_default(): void
    {
        config(['ai.limits.daily_calls' => 500]);

        BotSettings::acrossTenants()
            ->where('user_id', $this->alice->id)
            ->update(['ai_daily_call_limit' => 1]);

        $this->fakeAnswer();

        Tenant::for($this->alice, function () {
            $this->ask();
            $this->assertFalse($this->ask()['ok']);
        });

        $this->assertSame(1, app(AiSpend::class)->limitFor($this->alice->id));
    }

    /**
     * Zero is a real setting meaning "no AI at all", not an unset one meaning "use the
     * default". A falsy check here would silently hand a disabled tenant the full quota.
     */
    public function test_a_limit_of_zero_means_no_ai_rather_than_the_default(): void
    {
        config(['ai.limits.daily_calls' => 500]);

        BotSettings::acrossTenants()
            ->where('user_id', $this->alice->id)
            ->update(['ai_daily_call_limit' => 0]);

        $this->fakeAnswer();

        $result = Tenant::for($this->alice, fn () => $this->ask());

        $this->assertFalse($result['ok']);
        Http::assertNothingSent();
        $this->assertSame(0, app(AiSpend::class)->limitFor($this->alice->id));
    }

    public function test_yesterdays_calls_do_not_count_against_today(): void
    {
        config(['ai.limits.daily_calls' => 1]);
        $this->fakeAnswer();

        $yesterday = AiUsage::create([
            'user_id' => $this->alice->id, 'call_site' => 'chart_analyst',
            'model_requested' => 'x', 'ok' => true,
        ]);

        // Set after creation: Eloquent stamps `created_at` itself on insert, so passing it
        // to create() is silently ignored and the row lands today.
        $yesterday->forceFill(['created_at' => now()->subDay()])->saveQuietly();

        $result = Tenant::for($this->alice, fn () => $this->ask());

        $this->assertTrue($result['ok']);
    }

    public function test_the_allowance_reports_what_is_left(): void
    {
        config(['ai.limits.daily_calls' => 10]);
        $this->fakeAnswer();

        Tenant::for($this->alice, fn () => $this->ask());

        $allowance = app(AiSpend::class)->allowance($this->alice->id);

        $this->assertSame(['limit' => 10, 'used' => 1, 'remaining' => 9, 'exhausted' => false], $allowance);
    }

    // =========================================================================
    // ATTRIBUTION
    // =========================================================================

    public function test_a_call_with_no_tenant_in_scope_belongs_to_the_platform(): void
    {
        // Console work that has not named a tenant. Recorded against nobody rather than
        // attributed to whoever happens to be first in the users table.
        $this->fakeAnswer();

        $this->ask();

        $this->assertNull(AiUsage::acrossTenants()->sole()->user_id);
    }

    public function test_usage_rows_are_scoped_like_everything_else_a_tenant_owns(): void
    {
        $this->fakeAnswer();

        Tenant::for($this->alice, fn () => $this->ask());

        Tenant::for($this->bob, function () {
            $this->assertSame(0, AiUsage::query()->count());
        });
    }

    // =========================================================================
    // WHAT THE TENANT SEES
    // =========================================================================

    /**
     * A limit nobody can see is a limit that arrives as an unexplained failure. The
     * settings page has to answer "how many have I got left" before it has to answer
     * anything else about this feature.
     */
    public function test_the_settings_page_shows_what_is_left_of_the_allowance(): void
    {
        config(['ai.limits.daily_calls' => 25]);
        $this->fakeAnswer();

        Tenant::for($this->alice, fn () => $this->ask());

        Livewire::actingAs($this->alice)
            ->test(Settings::class)
            ->assertViewHas('allowance', fn (array $a) => $a['used'] === 1 && $a['remaining'] === 24)
            ->assertSee('AI Requests Today');
    }

    public function test_one_tenants_usage_never_appears_on_anothers_settings_page(): void
    {
        config(['ai.limits.daily_calls' => 25]);
        $this->fakeAnswer();

        Tenant::for($this->alice, fn () => $this->ask());

        Livewire::actingAs($this->bob)
            ->test(Settings::class)
            ->assertViewHas('allowance', fn (array $a) => $a['used'] === 0);
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    /**
     * @param  array<string, mixed>  $usage
     */
    private function fakeAnswer(string $served = 'anthropic/claude-sonnet-5', array $usage = ['prompt_tokens' => 100, 'completion_tokens' => 50]): void
    {
        Http::fake(['openrouter.ai/*' => Http::response([
            'model' => $served,
            'usage' => $usage,
            'choices' => [['message' => ['content' => json_encode(['verdict' => 'fine'])]]],
        ], 200)]);
    }

    /**
     * @return array{ok: bool, data: array<string, mixed>|null, error: string|null, model: string|null}
     */
    private function ask(string $model = 'anthropic/claude-sonnet-5'): array
    {
        return (new OpenRouter)->structured(
            model: $model,
            system: 'You judge charts.',
            brief: 'Is this a good setup?',
            schemaName: 'verdict',
            schema: ['type' => 'object', 'properties' => ['verdict' => ['type' => 'string']], 'required' => ['verdict']],
            callSite: 'chart_analyst',
        );
    }
}
