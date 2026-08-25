<?php

use App\Livewire\Forms\LoginForm;
use Illuminate\Support\Facades\Session;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.guest')] class extends Component
{
    public LoginForm $form;

    /**
     * Handle an incoming authentication request.
     */
    public function login(): void
    {
        $this->validate();

        $this->form->authenticate();

        Session::regenerate();

        $this->redirectIntended(default: route('dashboard', absolute: false), navigate: true);
    }
}; ?>

<div>
    <!-- Session Status -->
    <x-auth-session-status class="mb-4" :status="session('status')" />

    <h2 class="text-2xl font-semibold text-white">Sign in</h2>
    <p class="mt-1 text-sm text-gray-500">Welcome back.</p>

    <form wire:submit="login" class="mt-8 space-y-5">
        <div>
            <label for="email" class="block text-sm font-medium text-gray-300">{{ __('Email') }}</label>
            <input wire:model="form.email" id="email" name="email" type="email" required autofocus
                   autocomplete="username"
                   class="mt-1 block w-full rounded-md border-gray-700 bg-gray-800 text-white placeholder-gray-600 shadow-sm focus:border-yellow-500 focus:ring-yellow-500 sm:text-sm">
            <x-input-error :messages="$errors->get('form.email')" class="mt-2" />
        </div>

        <div>
            <div class="flex items-baseline justify-between">
                <label for="password" class="block text-sm font-medium text-gray-300">{{ __('Password') }}</label>

                @if (Route::has('password.request'))
                    <a href="{{ route('password.request') }}" wire:navigate
                       class="text-xs text-gray-500 hover:text-yellow-500">{{ __('Forgot your password?') }}</a>
                @endif
            </div>

            {{-- A reveal toggle, because a mistyped password on a page with no other clue is
                 the commonest reason somebody thinks their account is broken. --}}
            <div class="relative mt-1" x-data="{ show: false }">
                <input wire:model="form.password" id="password" name="password" required
                       autocomplete="current-password"
                       x-bind:type="show ? 'text' : 'password'"
                       class="block w-full rounded-md border-gray-700 bg-gray-800 pr-10 text-white placeholder-gray-600 shadow-sm focus:border-yellow-500 focus:ring-yellow-500 sm:text-sm">

                <button type="button" x-on:click="show = !show" tabindex="-1"
                        class="absolute inset-y-0 right-0 flex items-center px-3 text-gray-500 hover:text-gray-300">
                    <svg x-show="!show" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z" />
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                    </svg>
                    <svg x-show="show" x-cloak class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 0 0 1.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.451 10.451 0 0 1 12 4.5c4.756 0 8.773 3.162 10.065 7.498a10.522 10.522 0 0 1-4.293 5.774M6.228 6.228 3 3m3.228 3.228 3.65 3.65m7.894 7.894L21 21m-3.228-3.228-3.65-3.65m0 0a3 3 0 1 0-4.243-4.243" />
                    </svg>
                </button>
            </div>

            <x-input-error :messages="$errors->get('form.password')" class="mt-2" />
        </div>

        <label for="remember" class="flex items-center gap-2">
            <input wire:model="form.remember" id="remember" name="remember" type="checkbox"
                   class="rounded border-gray-600 bg-gray-800 text-yellow-500 focus:ring-yellow-500 focus:ring-offset-gray-900">
            <span class="text-sm text-gray-400">{{ __('Remember me') }}</span>
        </label>

        <button type="submit" wire:loading.attr="disabled"
                class="flex w-full justify-center rounded-md bg-yellow-500 px-3 py-2.5 text-sm font-semibold text-gray-900 hover:bg-yellow-400 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-yellow-500 disabled:opacity-60">
            <span wire:loading.remove wire:target="login">{{ __('Log in') }}</span>
            <span wire:loading wire:target="login">{{ __('Signing in…') }}</span>
        </button>

        @if (Route::has('register'))
            <p class="text-center text-sm text-gray-500">
                No account?
                <a href="{{ route('register') }}" wire:navigate class="text-yellow-500 hover:text-yellow-400">Create one</a>
            </p>
        @endif
    </form>
</div>
