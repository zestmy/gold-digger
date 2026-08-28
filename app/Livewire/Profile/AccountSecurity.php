<?php

namespace App\Livewire\Profile;

use App\Services\Auth\Totp;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Component;

/**
 * Account Security
 *
 * Two factors, and everywhere this account is signed in.
 *
 * ## Enrolment is two steps on purpose
 *
 * A secret is issued, and then a code from it has to be proved to work before the second
 * factor is enforced. Turning it on from the first step would let a mistyped or unsaved
 * secret lock somebody out of an account that can move money, with no way back in - which
 * is a worse outcome than not having 2FA at all.
 *
 * ## Recovery codes are shown once
 *
 * They are stored hashed, like passwords, because the server only ever needs to check one
 * rather than read it back. The consequence is that leaving this page loses them, so the
 * page says so where somebody about to leave it will read that.
 *
 * ## Disabling asks for the password
 *
 * Removing a second factor is exactly what somebody who has stolen a session would do
 * first. Asking for the password means a stolen cookie is not on its own enough.
 */
class AccountSecurity extends Component
{
    /** The secret being enrolled, before it has been proved. */
    public ?string $pendingSecret = null;

    public string $code = '';

    public string $password = '';

    /** Shown exactly once, immediately after enrolment. */
    public array $freshRecoveryCodes = [];

    public function mount(): void
    {
        $this->pendingSecret = null;
    }

    /**
     * Issue a secret. Nothing is enforced until a code from it works.
     */
    public function begin(Totp $totp): void
    {
        $this->reset('code', 'freshRecoveryCodes');
        $this->pendingSecret = $totp->secret();
    }

    public function cancel(): void
    {
        $this->reset('pendingSecret', 'code');
    }

    /**
     * Prove the secret, then turn it on.
     */
    public function confirm(Totp $totp): void
    {
        $this->validate(['code' => ['required', 'string', 'max:16']]);

        if ($this->pendingSecret === null) {
            return;
        }

        if ($totp->verify($this->pendingSecret, $this->code) === null) {
            $this->addError('code', 'That code is not right. Check your phone\'s clock is accurate, then try the next one.');

            return;
        }

        $codes = $totp->recoveryCodes();

        Auth::user()->forceFill([
            'two_factor_secret' => $this->pendingSecret,
            // Hashed, not encrypted. Single-use passwords get what passwords get.
            'two_factor_recovery_codes' => array_map(fn (string $c) => Hash::make($c), $codes),
            'two_factor_confirmed_at' => now(),
        ])->save();

        $this->freshRecoveryCodes = $codes;
        $this->reset('pendingSecret', 'code');

        $this->dispatch('notify', type: 'success', message: 'Two-factor authentication is on. Save your recovery codes now.');
    }

    /**
     * New codes, invalidating the old ones.
     */
    public function regenerateRecoveryCodes(Totp $totp): void
    {
        $user = Auth::user();

        if (! $user->hasTwoFactor()) {
            return;
        }

        $codes = $totp->recoveryCodes();

        $user->forceFill([
            'two_factor_recovery_codes' => array_map(fn (string $c) => Hash::make($c), $codes),
        ])->save();

        $this->freshRecoveryCodes = $codes;

        $this->dispatch('notify', type: 'success', message: 'New recovery codes issued. The old ones no longer work.');
    }

    /**
     * Turn it off, against the password.
     */
    public function disable(): void
    {
        $this->validate(['password' => ['required', 'string']]);

        if (! Hash::check($this->password, Auth::user()->password)) {
            $this->addError('password', 'That password is not right.');

            return;
        }

        Auth::user()->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            'two_factor_last_step' => null,
        ])->save();

        $this->reset('password', 'freshRecoveryCodes');

        $this->dispatch('notify', type: 'success', message: 'Two-factor authentication is off.');
    }

    /**
     * Sign out every other browser.
     *
     * Laravel's own `logoutOtherDevices` rewrites the password hash's remembered token, and
     * `AuthenticateSession` - now on the web guard - is what makes the other sessions
     * actually stop working rather than merely being listed as gone.
     */
    public function signOutOtherSessions(): void
    {
        $this->validate(['password' => ['required', 'string']]);

        if (! Hash::check($this->password, Auth::user()->password)) {
            $this->addError('password', 'That password is not right.');

            return;
        }

        Auth::logoutOtherDevices($this->password);

        // The rows as well as the guard: a listed session that no longer works is still a
        // confusing thing to show somebody who has just tried to remove it.
        DB::table('sessions')
            ->where('user_id', Auth::id())
            ->where('id', '!=', session()->getId())
            ->delete();

        $this->reset('password');

        $this->dispatch('notify', type: 'success', message: 'Every other browser has been signed out.');
    }

    /**
     * Where this account is signed in.
     *
     * Read from the sessions table, which this deployment uses - see `SESSION_DRIVER`. A
     * deployment on the array or cookie driver has nothing to list, and the view says so
     * rather than showing an empty table that looks like an answer.
     *
     * @return array<int, array<string, mixed>>
     */
    public function sessions(): array
    {
        if (config('session.driver') !== 'database') {
            return [];
        }

        return DB::table('sessions')
            ->where('user_id', Auth::id())
            ->orderByDesc('last_activity')
            ->limit(20)
            ->get()
            ->map(fn ($row) => [
                'current' => $row->id === session()->getId(),
                'ip' => $row->ip_address,
                'agent' => $this->describeAgent((string) $row->user_agent),
                'last_active' => Carbon::createFromTimestamp($row->last_activity)->diffForHumans(),
            ])
            ->all();
    }

    /**
     * A user agent string, shortened to the part somebody recognises.
     *
     * Not parsing: a rough guess at browser and platform is enough to answer "is that me?",
     * and pulling in an agent-parsing library to do it properly would be a dependency for a
     * cosmetic gain.
     */
    private function describeAgent(string $agent): string
    {
        $browser = match (true) {
            str_contains($agent, 'Edg/') => 'Edge',
            str_contains($agent, 'Chrome') => 'Chrome',
            str_contains($agent, 'Firefox') => 'Firefox',
            str_contains($agent, 'Safari') => 'Safari',
            default => 'Unknown browser',
        };

        $platform = match (true) {
            str_contains($agent, 'Windows') => 'Windows',
            str_contains($agent, 'Macintosh') => 'macOS',
            str_contains($agent, 'iPhone'), str_contains($agent, 'iPad') => 'iOS',
            str_contains($agent, 'Android') => 'Android',
            str_contains($agent, 'Linux') => 'Linux',
            default => 'unknown platform',
        };

        return "{$browser} on {$platform}";
    }

    public function render()
    {
        $user = Auth::user();

        return view('livewire.profile.account-security', [
            'enabled' => $user->hasTwoFactor(),
            'remaining' => $user->recoveryCodesRemaining(),
            'sessions' => $this->sessions(),
            'uri' => $this->pendingSecret === null ? null : app(Totp::class)->provisioningUri(
                $this->pendingSecret,
                (string) $user->email,
                (string) config('app.name'),
            ),
        ]);
    }
}
