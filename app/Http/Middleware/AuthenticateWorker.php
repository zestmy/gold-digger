<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards the endpoints the hosted session worker uses.
 *
 * ## Why not a bot token
 *
 * Every other machine credential here names one thing - a terminal, an account - so
 * revoking it stops exactly that. The worker is the opposite: it signs in and holds the
 * session for every hosted tenant, so a credential scoped to one account cannot express
 * what it does.
 *
 * That makes this token infrastructure rather than something a user is issued. It is set
 * by whoever deploys the platform, it lives beside the database password, and there is no
 * dashboard page that shows it. Rotating it means restarting the worker, which is the
 * honest cost of a credential that reaches everything.
 *
 * ## Unset refuses rather than falls open
 *
 * A missing `TELEGRAM_WORKER_TOKEN` is the state a fresh install is in. Treating that as
 * "no check required" would leave every session readable on any deployment where somebody
 * had not finished the setup, which is precisely the deployment least able to notice.
 */
class AuthenticateWorker
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = config('telegram.worker_token');

        if (! is_string($expected) || $expected === '') {
            return response()->json(['message' => 'No worker is configured.'], 503);
        }

        $presented = $request->bearerToken();

        // hash_equals, not ===, so a wrong token cannot be narrowed down by timing it.
        if (! is_string($presented) || ! hash_equals($expected, $presented)) {
            return response()->json(['message' => 'Invalid worker token.'], 401);
        }

        return $next($request);
    }
}
