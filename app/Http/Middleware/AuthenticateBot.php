<?php

namespace App\Http\Middleware;

use App\Models\BotToken;
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

        return $next($request);
    }
}
