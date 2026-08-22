<?php

use App\Http\Controllers\Auth\VerifyEmailController;
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
    if (config('auth.registration_enabled')) {
        Volt::route('register', 'pages.auth.register')
            ->name('register');
    }

    Volt::route('login', 'pages.auth.login')
        ->name('login');

    Volt::route('forgot-password', 'pages.auth.forgot-password')
        ->name('password.request');

    Volt::route('reset-password/{token}', 'pages.auth.reset-password')
        ->name('password.reset');
});

Route::middleware('auth')->group(function () {
    Volt::route('verify-email', 'pages.auth.verify-email')
        ->name('verification.notice');

    Route::get('verify-email/{id}/{hash}', VerifyEmailController::class)
        ->middleware(['signed', 'throttle:6,1'])
        ->name('verification.verify');

    Volt::route('confirm-password', 'pages.auth.confirm-password')
        ->name('password.confirm');

    Route::post('logout', function () {
        Auth::guard('web')->logout();
        request()->session()->invalidate();
        request()->session()->regenerateToken();

        return redirect('/');
    })->name('logout');
});
