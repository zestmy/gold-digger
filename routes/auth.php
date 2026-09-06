<?php

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

Route::middleware('guest')->group(function () {
    // Registration is off by default. This is a single-operator trading bot: an open signup
    // form on a box that holds broker credentials and trade history invites accounts nobody
    // asked for. Set REGISTRATION_ENABLED=true to run it as something people join.
    //
    // The route is not defined at all when disabled - rather than defined and blocked - so
    // Route::has('register') is the single source of truth, and the marketing page hides its
    // sign-up buttons instead of linking somewhere that answers 403.
    //
    // With it off, the first account comes from the console: php artisan user:create.
    if (config('auth.registration_enabled')) {
        Volt::route('register', 'pages.auth.register')
            ->name('register');
    }

    Volt::route('login', 'pages.auth.login')
        ->name('login');

    // Throttled because each submission sends an email somebody else receives, and a form
    // that will do that six hundred times a minute for any address typed into it is a
    // spam cannon pointed at whoever the attacker names.
    Volt::route('forgot-password', 'pages.auth.forgot-password')
        ->middleware('throttle:6,1')
        ->name('password.request');

    Volt::route('reset-password/{token}', 'pages.auth.reset-password')
        ->name('password.reset');
});

// No email verification routes. `User` does not implement MustVerifyEmail and nothing
// checks `verified`, so the notice page and the signed callback the skeleton shipped were
// reachable but did nothing anyone relied on.
Route::middleware('auth')->group(function () {
    Volt::route('confirm-password', 'pages.auth.confirm-password')
        ->name('password.confirm');

    Route::post('logout', function () {
        Auth::guard('web')->logout();
        request()->session()->invalidate();
        request()->session()->regenerateToken();

        return redirect('/');
    })->name('logout');
});
