<?php

use App\Http\Controllers\Api\Bot\CommandController;
use App\Http\Controllers\Api\Bot\FillController;
use App\Http\Controllers\Api\Bot\HeartbeatController;
use App\Http\Controllers\Api\Bot\LogController;
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

    // Push executor logs into bot_logs so /logs shows them.
    Route::post('logs', [LogController::class, 'store'])->name('api.bot.logs.store');
});
