<?php

namespace App\Support\Tenancy;

use App\Models\User;
use App\Services\Monitoring\ErrorReporter;
use Throwable;

/**
 * Run the same work for every tenant, without letting one of them stop the rest.
 *
 * ## The bug this fixes is not about scale
 *
 * `copier:protect` and `ai:decide` iterated every user in a plain `foreach`. A throw for one
 * tenant - a malformed symbol spec, a broker that answered strangely, a model that timed out
 * - aborted the whole command, and every tenant with a higher id was skipped. Silently, and
 * for as long as the cause persisted, which for anything deterministic is for ever.
 *
 * Two tenants in, that is invisible. Two hundred in, it means one customer's bad data stops
 * everybody else's stops being trailed. It was worth fixing at either size.
 *
 * ## Each tenant runs as themselves
 *
 * `Tenant::for()` around the work buys two things that used to be the caller's problem:
 * every model inside filters to that tenant, and any model call is attributed to their
 * allowance rather than to nobody.
 *
 * ## A failure is an incident, not a silence
 *
 * The throw goes to `ErrorReporter`, so it lands on `/logs` and reaches the operator through
 * the same path every other fault does - deduplicated, counted, and named with the tenant it
 * happened for. The alternative, and what happened before, is that the work simply did not
 * occur and nothing said so.
 */
final class TenantSweep
{
    /**
     * @param  iterable<int, User>  $users
     * @param  callable(User): void  $work
     * @return array{ran: int, failed: int}
     */
    public function each(iterable $users, callable $work): array
    {
        $ran = 0;
        $failed = 0;

        foreach ($users as $user) {
            try {
                Tenant::for($user, fn () => $work($user));
                $ran++;
            } catch (Throwable $e) {
                $failed++;

                // Reported as the tenant it failed for, so the incident on /logs is filed
                // beside the rest of that account's activity rather than against nobody.
                Tenant::for($user, fn () => app(ErrorReporter::class)->report($e));
            }
        }

        return ['ran' => $ran, 'failed' => $failed];
    }
}
