<?php

namespace App\Livewire\Forms;

use App\Models\User;
use App\Services\Auth\Totp;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Validate;
use Livewire\Form;

class LoginForm extends Form
{
    #[Validate('required|string|email')]
    public string $email = '';

    #[Validate('required|string')]
    public string $password = '';

    #[Validate('boolean')]
    public bool $remember = false;

    /**
     * The authenticator code, or a recovery code, once a second factor is being asked for.
     */
    #[Validate('nullable|string|max:64')]
    public string $code = '';

    /**
     * True once the password was right and the account wants a second factor.
     *
     * Held on the form rather than in the session because the credentials are re-checked on
     * the second submission anyway - so this only decides which field to show, and cannot be
     * flipped by a client to skip anything.
     */
    public bool $awaitingCode = false;

    /**
     * Attempt to authenticate the request's credentials.
     *
     * @throws ValidationException
     */
    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        // Validated without signing in, so a second factor can be demanded before a session
        // exists. `Auth::attempt()` would have established one, and an account with 2FA on
        // would have been signed in for the moment between the password and the code.
        $user = $this->credentialed();

        if ($user === null) {
            RateLimiter::hit($this->throttleKey());

            throw ValidationException::withMessages([
                'form.email' => trans('auth.failed'),
            ]);
        }

        if ($user->hasTwoFactor()) {
            $this->challenge($user);
        }

        Auth::login($user, $this->remember);

        RateLimiter::clear($this->throttleKey());
    }

    /**
     * The user these credentials belong to, or null.
     */
    private function credentialed(): ?User
    {
        $user = User::where('email', $this->email)->first();

        // Compared even when no such account exists, so the response time does not say
        // which addresses are registered.
        $matches = Hash::check($this->password, $user?->password ?? '$2y$12$'.str_repeat('x', 53));

        return ($user !== null && $matches) ? $user : null;
    }

    /**
     * Demand the second factor, and refuse until it is right.
     *
     * Every wrong code costs an attempt from the same throttle the password uses. A
     * six-digit code across three accepted windows is three chances in a million per try;
     * the throttle is what stops somebody buying enough tries.
     *
     * @throws ValidationException
     */
    private function challenge(User $user): void
    {
        $code = trim($this->code);

        if ($code === '') {
            $this->awaitingCode = true;

            throw ValidationException::withMessages([
                'form.code' => 'Enter the code from your authenticator app.',
            ]);
        }

        $step = app(Totp::class)->verify((string) $user->two_factor_secret, $code);

        if ($step !== null) {
            // A code is valid for its whole window, so an intercepted one could be replayed
            // inside its own thirty seconds. Recording the step spends it.
            if ((int) $user->two_factor_last_step === $step) {
                $this->rejectCode('That code has already been used. Wait for the next one.');
            }

            $user->forceFill(['two_factor_last_step' => $step])->save();

            return;
        }

        if ($user->useRecoveryCode($code)) {
            return;
        }

        $this->rejectCode('That code is not right.');
    }

    /**
     * @throws ValidationException
     */
    private function rejectCode(string $message): void
    {
        RateLimiter::hit($this->throttleKey());

        $this->awaitingCode = true;

        throw ValidationException::withMessages(['form.code' => $message]);
    }

    /**
     * Ensure the authentication request is not rate limited.
     */
    protected function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            return;
        }

        event(new Lockout(request()));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'form.email' => trans('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }

    /**
     * Get the authentication rate limiting throttle key.
     */
    protected function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->email).'|'.request()->ip());
    }
}
