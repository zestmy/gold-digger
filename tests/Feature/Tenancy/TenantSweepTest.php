<?php

namespace Tests\Feature\Tenancy;

use App\Models\BotLog;
use App\Models\User;
use App\Support\Tenancy\Tenant;
use App\Support\Tenancy\TenantSweep;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * Running the same work for every tenant.
 *
 * The bug this closes was not about scale. `copier:protect` and `ai:decide` iterated every
 * user in a plain foreach, so a throw for one account aborted the command and every account
 * with a higher id was skipped - silently, and for as long as the cause persisted, which for
 * anything deterministic is for ever.
 *
 * Two tenants in that is invisible. It still meant one customer's malformed data could stop
 * everybody else's stops being trailed, which is the worst kind of silent failure this
 * system has.
 */
class TenantSweepTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['alerts.telegram.token' => null, 'alerts.telegram.chat_id' => null]);
        Http::fake();
    }

    /**
     * The whole point. The tenant after the failure still runs.
     */
    public function test_one_tenant_failing_does_not_skip_the_rest(): void
    {
        $first = User::factory()->create();
        $broken = User::factory()->create();
        $last = User::factory()->create();

        $ran = [];

        $result = app(TenantSweep::class)->each(
            User::query()->orderBy('id')->get(),
            function (User $user) use (&$ran, $broken) {
                if ($user->is($broken)) {
                    throw new RuntimeException('this account has malformed data');
                }

                $ran[] = $user->id;
            },
        );

        $this->assertSame([$first->id, $last->id], $ran);
        $this->assertSame(2, $result['ran']);
        $this->assertSame(1, $result['failed']);
    }

    /**
     * A failure that nothing recorded is the same as the work silently not happening, which
     * is what used to occur.
     */
    public function test_a_failure_becomes_an_incident_against_the_tenant_it_happened_for(): void
    {
        $customer = User::factory()->create();

        app(TenantSweep::class)->each([$customer], function () {
            throw new RuntimeException('the broker answered strangely');
        });

        $log = BotLog::acrossTenants()->where('source', 'app')->sole();

        $this->assertSame('critical', $log->level);
        $this->assertStringContainsString('the broker answered strangely', $log->message);
        // Filed beside the rest of that account's activity, not against nobody.
        $this->assertSame($customer->id, $log->user_id);
    }

    /**
     * Each account runs as itself, so every model inside filters to them and any model call
     * is attributed to their allowance.
     */
    public function test_the_work_runs_as_the_tenant_it_is_for(): void
    {
        $a = User::factory()->create();
        $b = User::factory()->create();

        $seen = [];

        app(TenantSweep::class)->each([$a, $b], function () use (&$seen) {
            $seen[] = Tenant::current();
        });

        $this->assertSame([$a->id, $b->id], $seen);
    }

    /**
     * A sweep that left the last tenant current would leak that identity into whatever the
     * process did next - the same class of bug the middleware terminate hooks exist for.
     */
    public function test_no_tenant_is_left_current_afterwards(): void
    {
        $users = User::factory()->count(2)->create();

        app(TenantSweep::class)->each($users, fn () => null);

        $this->assertNull(Tenant::current());
    }

    public function test_a_failure_also_leaves_no_tenant_behind(): void
    {
        $users = User::factory()->count(2)->create();

        app(TenantSweep::class)->each($users, function () {
            throw new RuntimeException('boom');
        });

        $this->assertNull(Tenant::current());
    }

    public function test_an_empty_list_is_not_an_error(): void
    {
        $this->assertSame(['ran' => 0, 'failed' => 0], app(TenantSweep::class)->each([], fn () => null));
    }
}
