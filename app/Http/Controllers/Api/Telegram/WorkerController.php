<?php

namespace App\Http\Controllers\Api\Telegram;

use App\Http\Controllers\Controller;
use App\Models\TelegramAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The surface the hosted session worker talks to.
 *
 * ## Why this exists next to LoginController rather than inside it
 *
 * A self-hosted collector is one account: its token names the account, so every endpoint
 * can resolve "which one" from the credential. The worker is every hosted account at
 * once, so it has to say which one it means - a different shape of request, even though
 * the sign-in conversation on the other side is identical.
 *
 * The conversation itself is not duplicated. Both paths call the same
 * `LoginController::instructionFor()` and `applyReport()`, because two implementations of
 * one state machine is how you get a sign-in that works self-hosted and hangs hosted.
 *
 * ## Sessions travel, briefly
 *
 * `index()` hands out session strings, which is the whole point - the worker cannot
 * connect without them - and also the most dangerous response in this application. It is
 * reachable only with the infrastructure token, over the loopback or private network the
 * worker runs on, and it returns nothing for accounts a tenant runs themselves.
 */
class WorkerController extends Controller
{
    /**
     * Everything the worker should be doing, in one poll.
     *
     * Signed-in accounts so it can hold their connections open, and half-finished ones so
     * a person watching the dashboard is not waiting on a process that only looks at
     * accounts it already knows.
     */
    public function index(): JsonResponse
    {
        $accounts = TelegramAccount::hosted()
            ->whereNotIn('login_state', [TelegramAccount::FAILED])
            ->get()
            ->map(fn (TelegramAccount $account) => [
                'id' => $account->id,
                'label' => $account->label,
                'user_id' => $account->user_id,
                'login_state' => $account->login_state,
                // Null until a sign-in completes; the worker treats that as "drive the
                // conversation" rather than as an error.
                'session' => $account->session,
                'ingest_state' => $account->ingest_state ?? [],
            ]);

        return response()->json(['accounts' => $accounts]);
    }

    /**
     * What this account's sign-in is waiting on. Destructive: secrets come once.
     */
    public function login(TelegramAccount $account): JsonResponse
    {
        $this->assertHosted($account);

        $account->update(['last_seen_at' => now()]);

        return response()->json(LoginController::instructionFor($account));
    }

    /**
     * How that step went.
     */
    public function report(Request $request, TelegramAccount $account): JsonResponse
    {
        $this->assertHosted($account);

        $data = $request->validate([
            'state' => ['required', 'string', 'in:code_sent,password_needed,active,failed'],
            'message' => ['nullable', 'string', 'max:255'],
            'username' => ['nullable', 'string', 'max:64'],
            'name' => ['nullable', 'string', 'max:255'],
        ]);

        LoginController::applyReport($account, $data);

        return response()->json(['ok' => true]);
    }

    /**
     * Keep the session that a completed sign-in produced.
     *
     * Written on its own rather than as part of the "active" report, because a session
     * that arrives and a state that changes are different failures: losing the second
     * means a confusing dashboard, losing the first means the tenant signs in again.
     */
    public function storeSession(Request $request, TelegramAccount $account): JsonResponse
    {
        $this->assertHosted($account);

        $data = $request->validate([
            'session' => ['required', 'string', 'max:8192'],
        ]);

        $account->update(['session' => $data['session']]);

        return response()->json(['ok' => true]);
    }

    /**
     * Per-channel checkpoints, so a redeploy does not re-send everything it already sent.
     */
    public function storeState(Request $request, TelegramAccount $account): JsonResponse
    {
        $this->assertHosted($account);

        $data = $request->validate([
            'ingest_state' => ['required', 'array'],
        ]);

        $account->update(['ingest_state' => $data['ingest_state'], 'last_seen_at' => now()]);

        return response()->json(['ok' => true]);
    }

    /**
     * A self-hosted account is somebody else's to sign in. The worker touching one would
     * open a second session on a Telegram account, which Telegram reads as a compromise.
     */
    private function assertHosted(TelegramAccount $account): void
    {
        abort_unless($account->is_hosted, 404, 'This account is not hosted.');
    }
}
