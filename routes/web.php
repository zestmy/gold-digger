<?php

use App\Http\Controllers\ExpertAdvisorDownloadController;
use App\Livewire\Pages\Analytics;
use App\Livewire\Pages\BotLogs;
use App\Livewire\Pages\BrokerAccounts;
use App\Livewire\Pages\Dashboard;
use App\Livewire\Pages\LiveTrades;
use App\Livewire\Pages\Settings;
use App\Livewire\Pages\Setup;
use App\Livewire\Pages\SignalChannels;
use App\Livewire\Pages\SignalCopier;
use App\Livewire\Pages\Signals;
use App\Livewire\Pages\Strategies;
use App\Livewire\Pages\StrategyImprover;
use App\Livewire\Pages\TelegramAccounts;
use App\Livewire\Pages\TerminalSetup;
use App\Livewire\Pages\TradeHistory;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Six destinations, organised around what a subscriber does rather than how the
| system is built: read signals, choose providers, watch trades, connect a terminal
| and set risk, manage the account. Each destination is one menu item; the pages
| inside it are tabs.
|
| Route NAMES are unchanged from the sixteen-page layout so nothing that links by
| name moved; only the addresses did, and every old address redirects.
|
*/

Route::view('/', 'welcome');

Route::middleware(['auth'])->group(function () {
    // Home
    Route::get('/dashboard', Dashboard::class)->name('dashboard');

    // Signals: the system's own, and the copied ones.
    Route::get('/signals', Signals::class)->name('signals');
    Route::get('/signals/copied', SignalCopier::class)->name('signals.copier');

    // Providers: which Telegram channels are followed, and the accounts that read them.
    Route::get('/providers', SignalChannels::class)->name('signals.channels');
    Route::get('/providers/accounts', TelegramAccounts::class)->name('signals.accounts');

    // Trades: what is open, what happened, what it added up to.
    Route::get('/trades', LiveTrades::class)->name('trades.live');
    Route::get('/trades/history', TradeHistory::class)->name('trades.history');
    Route::get('/trades/performance', Analytics::class)->name('analytics');

    // Auto-Trade: how a signal becomes a position on the subscriber's own terminal.
    Route::get('/auto-trade', Setup::class)->name('setup');
    Route::get('/auto-trade/terminal', TerminalSetup::class)->name('terminal');
    // Behind auth: the archive is built per request with this dashboard's URL in it.
    Route::get('/auto-trade/terminal/download', ExpertAdvisorDownloadController::class)->name('terminal.download');
    Route::get('/auto-trade/accounts', BrokerAccounts::class)->name('broker-accounts');
    Route::get('/auto-trade/risk', Settings::class)->name('settings');

    // Settings: the account itself, and what it has been told.
    Route::view('/settings', 'profile')->name('profile');
    Route::get('/settings/activity', BotLogs::class)->name('logs');

    // Operator tools. The strategy parameters are the product's, not the subscriber's:
    // a subscriber chooses instruments and risk, and these pages tune what generates
    // the signals they receive.
    Route::middleware('admin')->group(function () {
        Route::get('/strategies', Strategies::class)->name('strategies');
        Route::get('/strategies/improve', StrategyImprover::class)->name('strategies.improve');
    });

    // The addresses these pages used to have. Bookmarks and old alert links keep working.
    foreach ([
        '/trades/live' => '/trades',
        '/analytics' => '/trades/performance',
        // The market scan was removed; its addresses land on the signals it ranked.
        '/analysis' => '/signals',
        '/signals/scan' => '/signals',
        '/signals/copier' => '/signals/copied',
        '/signals/channels' => '/providers',
        '/signals/accounts' => '/providers/accounts',
        '/setup' => '/auto-trade',
        '/terminal' => '/auto-trade/terminal',
        '/terminal/download' => '/auto-trade/terminal/download',
        '/broker-accounts' => '/auto-trade/accounts',
        '/logs' => '/settings/activity',
        '/profile' => '/settings',
    ] as $old => $new) {
        Route::redirect($old, $new, 301);
    }
});

require __DIR__.'/auth.php';
