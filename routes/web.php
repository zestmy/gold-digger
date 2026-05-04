<?php

use App\Livewire\Pages\Analytics;
use App\Livewire\Pages\BotLogs;
use App\Livewire\Pages\BrokerAccounts;
use App\Livewire\Pages\Dashboard;
use App\Livewire\Pages\LiveTrades;
use App\Livewire\Pages\Settings;
use App\Livewire\Pages\Strategies;
use App\Livewire\Pages\TradeHistory;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Gold Digger Trading Bot Dashboard Routes
|
| All dashboard routes are protected by auth middleware.
| Guest routes (login, register) are in auth.php.
|
*/

// Landing page - redirects to dashboard if authenticated
Route::view('/', 'welcome');

// Profile page (from Breeze)
Route::view('profile', 'profile')
    ->middleware(['auth'])
    ->name('profile');

/*
|--------------------------------------------------------------------------
| Authenticated Dashboard Routes
|--------------------------------------------------------------------------
*/
Route::middleware(['auth'])->group(function () {
    // Main Dashboard
    Route::get('/dashboard', Dashboard::class)->name('dashboard');

    // Trades
    Route::get('/trades/live', LiveTrades::class)->name('trades.live');
    Route::get('/trades/history', TradeHistory::class)->name('trades.history');

    // Configuration
    Route::get('/strategies', Strategies::class)->name('strategies');
    Route::get('/broker-accounts', BrokerAccounts::class)->name('broker-accounts');

    // Analytics & Monitoring
    Route::get('/analytics', Analytics::class)->name('analytics');
    Route::get('/settings', Settings::class)->name('settings');
    Route::get('/logs', BotLogs::class)->name('logs');
});

require __DIR__.'/auth.php';
