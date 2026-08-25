<?php

use App\Http\Controllers\Api\Bot\CandleController;
use App\Http\Controllers\Api\Bot\CommandController;
use App\Http\Controllers\Api\Bot\FillController;
use App\Http\Controllers\Api\Bot\HeartbeatController;
use App\Http\Controllers\Api\Bot\LogController;
use App\Http\Controllers\Api\Bot\PositionController;
use App\Http\Controllers\Api\Telegram\CollectorController;
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

Route::prefix('v1/bot')->middleware('bot.auth')->group(function () {
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

Route::prefix('v1/telegram')->middleware('bot.auth')->group(function () {
    // What to forward. The account sees far more than the copier should be shown.
    Route::get('channels', [CollectorController::class, 'index'])->name('api.telegram.channels.index');

    // What the account can see, so channels can be picked from a list rather than by id.
    Route::post('channels', [CollectorController::class, 'announce'])->name('api.telegram.channels.announce');

    // Messages. Idempotent on chat + message id, so a retry cannot become a second trade.
    Route::post('messages', [CollectorController::class, 'store'])->name('api.telegram.messages.store');
});
