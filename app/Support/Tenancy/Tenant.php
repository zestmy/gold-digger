<?php

namespace App\Support\Tenancy;

use App\Models\User;
use Closure;
use Illuminate\Support\Facades\Auth;

/**
 * Tenant
 *
 * Who the current request belongs to, for the purposes of automatic query scoping.
 *
 * ## Why this exists at all
 *
 * Isolation used to be enforced by writing `where('user_id', Auth::id())` in every query -
 * 93 of them at the last count. That works right up until somebody forgets once, and
 * forgetting once is a data breach rather than a bug. `/logs` is what forgetting once
 * looked like: every tenant read every other tenant's executor logs, any of them could
 * delete a row by id, and "Clear all" truncated the table for the whole platform.
 *
 * So the filter moves into the model layer, where it applies whether or not anyone
 * remembered. The hand-written `where()` calls stay - they are now redundant rather than
 * load-bearing, and removing 93 of them in the same change that introduces the mechanism
 * would mean trusting the new thing before it has run anywhere.
 *
 * ## Where the tenant comes from, in order
 *
 * 1. An id set explicitly by `actAs()` - the bot API does this from the bearer token, so a
 *    machine request is scoped to the account that issued the token rather than to nobody.
 * 2. The authenticated web user. This covers dashboard requests and Livewire, including
 *    Livewire component tests, which never traverse HTTP middleware.
 * 3. Nothing. Console commands and queued jobs run here.
 *
 * ## Why "nothing" means "no filter" rather than "no rows"
 *
 * Because the alternative silently breaks the trading engine. `bot:monitor`,
 * `copier:protect` and `ai:decide` all iterate every user by design, and the backtester
 * reads across accounts on purpose. A scope that returned nothing outside a request would
 * turn all of that into a system that quietly stops trading - the worst possible failure
 * for this application, and one that no test would catch because the rows are simply
 * absent rather than wrong.
 *
 * The trade-off is stated plainly: this mechanism protects the surfaces where tenants
 * meet each other's data - the dashboard and the API - and console code remains
 * responsible for its own scoping. That is where the risk actually lives.
 */
final class Tenant
{
    /** Explicitly nominated tenant, set by middleware or by a job that knows its owner. */
    private static ?int $current = null;

    /** True while a deliberate cross-tenant read is in progress. */
    private static bool $suspended = false;

    /**
     * Scope everything from here to this user.
     */
    public static function actAs(int|User|null $user): void
    {
        self::$current = $user instanceof User ? $user->id : $user;
    }

    /**
     * Forget the nominated tenant. Called between requests in long-running workers, and by
     * tests that would otherwise leak one case's tenant into the next.
     */
    public static function forget(): void
    {
        self::$current = null;
        self::$suspended = false;
    }

    /**
     * Whose data should a query see, or null for "do not filter".
     */
    public static function current(): ?int
    {
        if (self::$suspended) {
            return null;
        }

        if (self::$current !== null) {
            return self::$current;
        }

        // Auth rather than a middleware assignment, so that Livewire components under test
        // are scoped exactly as they are in a browser. `Livewire::test()` does not run the
        // HTTP middleware stack, and a mechanism that only works in production is not one
        // the test suite can hold to account.
        return Auth::hasUser() ? Auth::id() : null;
    }

    /**
     * Run a callback with tenant scoping switched off.
     *
     * For reads that are legitimately cross-tenant: the health monitor's sweep, the
     * reconciler matching a ticket, an admin console. Deliberately awkward to type, and
     * deliberately scoped to a closure - a global "off" switch would be left on.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function acrossTenants(Closure $callback): mixed
    {
        $was = self::$suspended;
        self::$suspended = true;

        try {
            return $callback();
        } finally {
            self::$suspended = $was;
        }
    }

    /**
     * Run a callback as one specific tenant, restoring whatever was current afterwards.
     *
     * This is what a per-tenant queued job uses: the job knows its owner, and everything it
     * touches should be filtered to them without every query saying so.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function for(int|User $user, Closure $callback): mixed
    {
        $was = self::$current;
        $wasSuspended = self::$suspended;

        self::$current = $user instanceof User ? $user->id : $user;
        self::$suspended = false;

        try {
            return $callback();
        } finally {
            self::$current = $was;
            self::$suspended = $wasSuspended;
        }
    }
}
