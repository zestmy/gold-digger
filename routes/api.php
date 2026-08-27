<?php

use App\Http\Controllers\Api\Bot\CandleController;
use App\Http\Controllers\Api\Bot\CommandController;
use App\Http\Controllers\Api\Bot\FillController;
use App\Http\Controllers\Api\Bot\HeartbeatController;
use App\Http\Controllers\Api\Bot\LogController;
use App\Http\Controllers\Api\Bot\PositionController;
use App\Http\Controllers\Api\Telegram\CollectorController;
use App\Http\Controllers\Api\Telegram\LoginController;
use App\Http\Controllers\Api\Telegram\WorkerController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Bot API Routes
|--------------------------------------------------------------------------
|
| The contract between the dashboard and whichever executor is running - today
| the MQL5 Expert Advisor in mql5/, tomorrow possibly a Python bot or a hosted
| MT5 adapter. Every route is authenticated with a bearer token from bot_tokens.
|
| These are stateless machine endpoints: no sessions, no CSRF, no cookies.
|
| The EA polls GET /commands on a timer and POSTs back to /commands/{id}/result,
| /fills, /heartbeat and /logs. See docs/MT5_EA_BRIDGE.md for the full protocol.
|
*/

Route::prefix('v1/bot')->middleware(['bot.auth', 'throttle:executor'])->group(function () {
    // Claim work. Accept: text/plain returns the EA's tab-separated wire format.
    Route::get('commands', [CommandController::class, 'index'])->name('api.bot.commands.index');
    Route::post('commands/{command}/result', [CommandController::class, 'result'])->name('api.bot.commands.result');

    // Report what the broker actually did.
    Route::post('fills', [FillController::class, 'store'])->name('api.bot.fills.store');

    // Liveness plus the kill-switch state the executor must honour.
    Route::post('heartbeat', [HeartbeatController::class, 'store'])->name('api.bot.heartbeat');

    // Push closed bars. A genuinely new bar is what triggers signal generation, so this
    // is the entry point of the strategy layer as well as a data endpoint.
    Route::post('candles', [CandleController::class, 'store'])->name('api.bot.candles.store');

    // Full snapshot of what the terminal actually holds, so `trades` can be corrected.
    // Positions opened or closed while nothing was listening leave no event to replay.
    Route::post('positions', [PositionController::class, 'store'])->name('api.bot.positions.store');

    // Push executor logs into bot_logs so /logs shows them.
    Route::post('logs', [LogController::class, 'store'])->name('api.bot.logs.store');
});

/*
|--------------------------------------------------------------------------
| Telegram Collector Routes
|--------------------------------------------------------------------------
|
| The same bearer-token contract, for a different outside process: a client
| logged in as a real Telegram account, which is the only way to read a channel
| this application was never added to.
|
| It lives outside the app on purpose - an MTProto session file is a full account
| credential - so this is the whole of its surface. See tools/telegram-collector/.
|
*/

Route::prefix('v1/telegram')->middleware(['bot.auth', 'throttle:collector'])->group(function () {
    // What to forward. The account sees far more than the copier should be shown.
    Route::get('channels', [CollectorController::class, 'index'])->name('api.telegram.channels.index');

    // What the account can see, so channels can be picked from a list rather than by id.
    Route::post('channels', [CollectorController::class, 'announce'])->name('api.telegram.channels.announce');

    // What `@someone` turned out to be. Only a signed-in client can answer this.
    Route::post('channels/resolve', [CollectorController::class, 'resolve'])->name('api.telegram.channels.resolve');

    // Messages. Idempotent on chat + message id, so a retry cannot become a second trade.
    Route::post('messages', [CollectorController::class, 'store'])->name('api.telegram.messages.store');

    // Signing in, relayed. The dashboard takes the phone and the code; the collector does
    // the sign-in and keeps the session. Nothing about it is stored here.
    Route::get('login', [LoginController::class, 'show'])->name('api.telegram.login.show');
    Route::post('login', [LoginController::class, 'store'])->name('api.telegram.login.store');
});

/*
|--------------------------------------------------------------------------
| Hosted session worker
|--------------------------------------------------------------------------
|
| The same conversation as above, for accounts the platform signs in on the tenant's
| behalf rather than ones they run a collector for themselves. Adding a Telegram account
| through a browser is table stakes for a product people sign up to; requiring Python and
| a file on disk is not.
|
| The cost is stated plainly because it is real: these endpoints hand out sessions that
| can read every chat on a tenant's account. They are guarded by an infrastructure token
| rather than an issued one, and they should not be exposed beyond the network the worker
| runs on. See tools/telegram-worker/.
|
*/

Route::prefix('v1/telegram/worker')->middleware(['worker.auth', 'throttle:worker'])->group(function () {
    // Every hosted account worth acting on, with the sessions needed to connect.
    Route::get('accounts', [WorkerController::class, 'index'])->name('api.telegram.worker.accounts');

    Route::get('accounts/{account}/login', [WorkerController::class, 'login'])->name('api.telegram.worker.login');
    Route::post('accounts/{account}/login', [WorkerController::class, 'report'])->name('api.telegram.worker.report');

    // Kept separately from the "active" report: a lost state is a confusing page, a lost
    // session is a tenant signing in again.
    Route::put('accounts/{account}/session', [WorkerController::class, 'storeSession'])->name('api.telegram.worker.session');

    Route::put('accounts/{account}/state', [WorkerController::class, 'storeState'])->name('api.telegram.worker.state');
});

// The ingest half, for hosted accounts. Deliberately the same controller the self-hosted
// collector posts to: idempotency on chat plus message id, the channel switch, and the
// parse pipeline are all things there must only ever be one of.
Route::prefix('v1/telegram/worker/accounts/{account}')
    ->middleware(['worker.auth', 'worker.account', 'throttle:worker'])
    ->group(function () {
        Route::get('channels', [CollectorController::class, 'index'])->name('api.telegram.worker.channels');
        Route::post('channels', [CollectorController::class, 'announce'])->name('api.telegram.worker.announce');
        Route::post('channels/resolve', [CollectorController::class, 'resolve'])->name('api.telegram.worker.resolve');
        Route::post('messages', [CollectorController::class, 'store'])->name('api.telegram.worker.messages');
    });
