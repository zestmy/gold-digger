<?php

namespace App\Http\Middleware;

use App\Models\TelegramAccount;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gives a worker request the identity a bot token would have carried.
 *
 * The collector endpoints resolve "which account, and whose" from the token that
 * authenticated. The worker holds one credential for all of them, so the account comes
 * from the route instead - and the point of doing it here, rather than inside each
 * controller, is that the ingest path then needs no branch at all. One implementation
 * stores messages, whoever fetched them.
 *
 * Hosted-ness is checked here for the same reason it is checked in WorkerController: a
 * self-hosted account is signed in on somebody's own machine, and a second session opened
 * against it is indistinguishable from a compromise.
 */
class BindWorkerAccount
{
    public function handle(Request $request, Closure $next): Response
    {
        $account = $request->route('account');

        if (! $account instanceof TelegramAccount) {
            $account = TelegramAccount::find($account);
        }

        if ($account === null || ! $account->is_hosted) {
            return response()->json(['message' => 'This account is not hosted.'], 404);
        }

        $request->attributes->set('telegram_account', $account);
        $request->attributes->set('bot_user', $account->user);

        return $next($request);
    }
}
