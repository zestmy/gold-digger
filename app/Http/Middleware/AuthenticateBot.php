<?php

namespace App\Http\Middleware;

use App\Models\BotToken;
use App\Support\Tenancy\Tenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticate Bot Middleware
 *
 * Guards every executor endpoint with a bearer token from the bot_tokens table.
 *
 * WHY not Sanctum? Sanctum is built around SPA cookies and user-facing personal access
 * tokens. This is one long-lived machine credential per terminal, scoped to a broker
 * account, and adding a package for that is more moving parts than the feature needs.
 *
 * On success the resolved token and user are attached to the request so controllers
 * never have to re-parse the header.
 */
class AuthenticateBot
{
    public function handle(Request $request, Closure $next): Response
    {
        $plaintext = $request->bearerToken();

        if (! is_string($plaintext) || $plaintext === '') {
            return response()->json(['message' => 'Missing bot token.'], 401);
        }

        $token = BotToken::resolve($plaintext);

        // resolve() returns null for unknown, revoked and expired alike, so the
        // response cannot be used to probe which tokens once existed.
        if ($token === null) {
            return response()->json(['message' => 'Invalid bot token.'], 401);
        }

        $token->touchLastUsed();

        $request->attributes->set('bot_token', $token);
        $request->attributes->set('bot_user', $token->user);

        // A machine request has no session, so nothing downstream would otherwise know
        // whose data it is looking at. Naming the tenant here means every model carrying
        // BelongsToTenant filters itself for the rest of this request - so a controller
        // that forgets to scope a lookup gets an empty result rather than another
        // account's row. Set after resolve(), because resolving the token is itself a
        // query and must see every token rather than one tenant's.
        Tenant::actAs($token->user_id);

        return $next($request);
    }

    /**
     * Put the tenant back down once the response has gone.
     *
     * `Tenant` holds a static, and a static set during a request is a static that is still
     * set after it. Under PHP-FPM the process ends and nothing notices; under Octane, in a
     * queue worker, or in a test suite running hundreds of requests in one process, the
     * next piece of work inherits this tenant and reads somebody else's data through it.
     *
     * That is the same failure this middleware exists to prevent, arriving by the back
     * door, so it is closed here rather than left to the runtime to make unlikely.
     */
    public function terminate(Request $request, Response $response): void
    {
        Tenant::forget();
    }
}
