<?php

namespace App\Providers;

use App\Models\BotToken;
use App\Models\User;
use App\Observers\UserObserver;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Register the UserObserver to handle automatic setup
        // when new users register (creates BotSettings + default Strategy)
        User::observe(UserObserver::class);

        $this->defineRateLimits();
    }

    /**
     * Budgets for the machine endpoints.
     *
     * ## Why these existed nowhere before
     *
     * Because there was one operator running one terminal, and the only client was an EA
     * whose poll interval they chose themselves. With customers, neither half of that
     * holds: a misconfigured `Timer` on somebody's chart, or one leaked token, becomes
     * sustained load on the same box that runs MySQL, the queue and every other tenant's
     * dashboard. There is no autoscaler here to absorb that.
     *
     * ## Keyed by token, not by IP
     *
     * Two reasons, and both matter. Several terminals legitimately share one office IP, so
     * an IP budget throttles innocent tenants together. And a single tenant hammering the
     * API must not be able to spend anybody else's allowance - which is exactly what a
     * shared key would let them do.
     *
     * The token is read from the header rather than from the resolved model, because
     * throttling has to work for requests that fail authentication too. Hashed, so the
     * cache never holds a live credential in a key.
     */
    private function defineRateLimits(): void
    {
        // The EA's steady state is one poll plus a heartbeat every few seconds, with
        // bursts when it flushes a backlog of bars or logs after being offline. This is
        // several times that, and far below what a runaway timer produces.
        RateLimiter::for('executor', fn (Request $request) => Limit::perMinute(300)
            ->by($this->credentialKey($request))
        );

        // A collector forwards messages as they arrive in channels a person subscribes to.
        // Even a busy signal channel is a few messages a minute.
        RateLimiter::for('collector', fn (Request $request) => Limit::perMinute(120)
            ->by($this->credentialKey($request))
        );

        // Infrastructure rather than a customer: one process serving every hosted session,
        // so its legitimate volume scales with the tenant count.
        RateLimiter::for('worker', fn (Request $request) => Limit::perMinute(600)
            ->by($this->credentialKey($request))
        );
    }

    /**
     * A stable, non-secret identifier for whoever is making this request.
     *
     * Falls back to the IP for a request carrying no bearer token at all - those are
     * rejected by the auth middleware anyway, and an unauthenticated flood should still
     * meet a limit before it meets the database.
     */
    private function credentialKey(Request $request): string
    {
        $bearer = $request->bearerToken();

        return is_string($bearer) && $bearer !== ''
            ? 'tok:'.BotToken::hash($bearer)
            : 'ip:'.(string) $request->ip();
    }
}
