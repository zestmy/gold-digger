<?php

namespace App\Http\Controllers\Api\Telegram;

use App\Http\Controllers\Controller;
use App\Models\TelegramAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Relays a Telegram sign-in between the dashboard and a collector.
 *
 * ## What moves, and what stays put
 *
 * The dashboard takes the phone number and the code, because asking somebody to open a
 * terminal to add an account is a five-second task turned into a session. The collector
 * performs the sign-in and keeps the session, on the machine that will use it.
 *
 * So this endpoint is a relay and holds nothing. The dashboard ends up with a row saying
 * "signed in"; the credential that can read every chat on the account lives where the
 * reading happens.
 *
 * ## The secrets are cached, never stored
 *
 * A code, and a two-step password when one is set, pass through on their way to the
 * collector. They go in the cache with a short expiry and are deleted the instant they are
 * collected - never written to a column, never logged, never returned twice. Delivering
 * one is destructive by design: a second collector asking gets nothing, which is what
 * stops a stolen token from replaying a sign-in.
 *
 * This is better than keeping a session here and worse than typing the code on the
 * collector directly, and the page says so rather than implying the choice is free.
 */
class LoginController extends Controller
{
    /** Long enough to type a code that has just arrived, short enough not to linger. */
    private const SECRET_TTL_SECONDS = 300;

    /**
     * What, if anything, this collector should be doing about signing in.
     */
    public function show(Request $request): JsonResponse
    {
        $account = $this->account($request);

        if ($account === null) {
            return response()->json(['action' => 'none']);
        }

        $account->update(['last_seen_at' => now()]);

        return response()->json(self::instructionFor($account));
    }

    /**
     * The next step of a sign-in, and the secrets that step needs.
     *
     * Shared with the hosted worker, which drives the identical conversation for an
     * account it was handed rather than one a token names. Keeping it in one place is
     * what stops the two paths drifting into subtly different state machines - the sort
     * of divergence that shows up as a sign-in that works self-hosted and hangs hosted.
     *
     * Calling this is destructive: a secret is handed over exactly once.
     */
    public static function instructionFor(TelegramAccount $account): array
    {
        return match ($account->login_state) {
            TelegramAccount::REQUESTED => [
                'action' => 'send_code',
                'phone' => $account->login_phone,
            ],
            TelegramAccount::CODE_SUBMITTED => [
                'action' => 'sign_in',
                'phone' => $account->login_phone,
                // Handed over exactly once.
                'code' => self::take($account, 'code'),
            ],
            TelegramAccount::PASSWORD_SUBMITTED => [
                'action' => 'password',
                'password' => self::take($account, 'password'),
            ],
            default => ['action' => 'none'],
        };
    }

    /**
     * The collector reporting how it went.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'state' => ['required', 'string', 'in:code_sent,password_needed,active,failed'],
            'message' => ['nullable', 'string', 'max:255'],
            'username' => ['nullable', 'string', 'max:64'],
            'name' => ['nullable', 'string', 'max:255'],
        ]);

        $account = $this->account($request);

        if ($account === null) {
            return response()->json(['message' => 'This token is not bound to an account.'], 404);
        }

        self::applyReport($account, $data);

        return response()->json(['ok' => true]);
    }

    /**
     * Record how a step went, whoever carried it out.
     */
    public static function applyReport(TelegramAccount $account, array $data): void
    {
        $account->advance($data['state'], $data['message'] ?? null);

        if ($data['state'] === TelegramAccount::ACTIVE) {
            $account->update(array_filter([
                'telegram_username' => $data['username'] ?? null,
                'display_name' => $data['name'] ?? null,
            ]) + ['last_seen_at' => now(), 'login_phone' => null]);

            // Nothing left to relay. A completed sign-in should leave no trace of the
            // conversation that produced it.
            self::forget($account);
        }

        if ($data['state'] === TelegramAccount::FAILED) {
            self::forget($account);
        }
    }

    /**
     * Read a relayed secret and destroy it in the same breath.
     */
    private static function take(TelegramAccount $account, string $what): ?string
    {
        $key = self::key($account, $what);
        $value = Cache::get($key);

        Cache::forget($key);

        return is_string($value) ? $value : null;
    }

    private static function forget(TelegramAccount $account): void
    {
        Cache::forget(self::key($account, 'code'));
        Cache::forget(self::key($account, 'password'));
    }

    /**
     * Put a secret where the collector's next poll will find it, once.
     */
    public static function relay(TelegramAccount $account, string $what, string $value): void
    {
        Cache::put(self::key($account, $what), $value, self::SECRET_TTL_SECONDS);
    }

    private static function key(TelegramAccount $account, string $what): string
    {
        return "telegram.login.{$account->id}.{$what}";
    }

    private function account(Request $request): ?TelegramAccount
    {
        $token = $request->attributes->get('bot_token');

        return $token === null
            ? null
            : TelegramAccount::where('bot_token_id', $token->id)->first();
    }
}
