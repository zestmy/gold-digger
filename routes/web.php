<?php

use App\Livewire\Pages\Analytics;
use App\Livewire\Pages\BotLogs;
use App\Livewire\Pages\BrokerAccounts;
use App\Livewire\Pages\Dashboard;
use App\Livewire\Pages\LiveTrades;
use App\Livewire\Pages\Settings;
use App\Livewire\Pages\Signals;
use App\Livewire\Pages\Strategies;
use App\Livewire\Pages\TerminalSetup;
use App\Livewire\Pages\SignalCopier;
use App\Livewire\Pages\StrategyImprover;
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

    // Every decision the strategy layer made, including the refusals.
    Route::get('/signals', Signals::class)->name('signals');
    Route::get('/signals/copier', SignalCopier::class)->name('signals.copier');

    // Configuration
    Route::get('/strategies', Strategies::class)->name('strategies');
    Route::get('/strategies/improve', StrategyImprover::class)->name('strategies.improve');
    Route::get('/broker-accounts', BrokerAccounts::class)->name('broker-accounts');
    Route::get('/terminal', TerminalSetup::class)->name('terminal');
    // Behind auth: the archive is built per request with this dashboard's URL in it.
    Route::get('/terminal/download', \App\Http\Controllers\ExpertAdvisorDownloadController::class)->name('terminal.download');

    // Analytics & Monitoring
    Route::get('/analytics', Analytics::class)->name('analytics');
    Route::get('/settings', Settings::class)->name('settings');
    Route::get('/logs', BotLogs::class)->name('logs');
});

require __DIR__.'/auth.php';
