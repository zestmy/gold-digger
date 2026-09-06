<?php

use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.guest')] class extends Component
{
    public string $email = '';

    /**
     * What the form says whatever the address was.
     *
     * The broker's own status strings say "We can't find a user with that email address",
     * which turns this form into a directory: type an address, learn whether it has an
     * account here. On a box that holds broker credentials, knowing which addresses to
     * phish is most of the work.
     */
    public const SENT = 'If that address has an account, a reset link has been sent to it.';

    /**
     * Send a password reset link to the provided email address.
     */
    public function sendPasswordResetLink(): void
    {
        $this->validate([
            'email' => ['required', 'string', 'email'],
        ]);

        // The route carries throttle:6,1 too, but that only meets the page load: the
        // submission arrives through Livewire's own endpoint, which does not re-run a
        // route's throttle. This is the limit that actually binds, and it is keyed the way
        // the login form's is, so one caller cannot spend everybody's allowance.
        $key = Str::transliterate(Str::lower($this->email).'|'.request()->ip());

        if (RateLimiter::tooManyAttempts($key, 6)) {
            $this->addError('email', 'Too many attempts. Try again in a minute.');

            return;
        }

        RateLimiter::hit($key);

        // The status is deliberately not shown. Whatever the broker found, the caller is
        // told the same thing - see SENT.
        Password::sendResetLink($this->only('email'));

        $this->reset('email');

        session()->flash('status', self::SENT);
    }
}; ?>

<div>
    <div class="mb-4 text-sm text-gray-600 dark:text-gray-400">
        {{ __('Forgot your password? No problem. Just let us know your email address and we will email you a password reset link that will allow you to choose a new one.') }}
    </div>

    <!-- Session Status -->
    <x-auth-session-status class="mb-4" :status="session('status')" />

    <form wire:submit="sendPasswordResetLink">
        <!-- Email Address -->
        <div>
            <x-input-label for="email" :value="__('Email')" />
            <x-text-input wire:model="email" id="email" class="block mt-1 w-full" type="email" name="email" required autofocus />
            <x-input-error :messages="$errors->get('email')" class="mt-2" />
        </div>

        <div class="flex items-center justify-end mt-4">
            <x-primary-button>
                {{ __('Email Password Reset Link') }}
            </x-primary-button>
        </div>
    </form>
</div>
