<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Trade Commands Migration
 *
 * The queue the dashboard writes to and the executor reads from. This is what turns
 * the Start/Stop/Close All buttons into real actions, and it is deliberately
 * executor-agnostic: the MQL5 EA, a Python bot, or a hosted API adapter all consume
 * the same rows.
 *
 * WHY a queue instead of the dashboard calling the terminal directly?
 * - The terminal lives on a Windows VPS behind NAT; it can poll out, but nothing can
 *   reliably call in.
 * - Commands survive a restart on either side. A click is not lost because the EA
 *   happened to be reconnecting.
 * - Every command keeps its outcome, so /logs can show what was asked and what the
 *   broker actually did.
 *
 * WHY idempotency_key?
 * A duplicate fill is the expensive failure mode in trading. The EA may retry after a
 * network timeout without knowing whether the first attempt landed. The unique key
 * means a retried enqueue collapses onto the existing row instead of opening a second
 * position.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trade_commands', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->constrained()
                ->cascadeOnDelete();

            // Which account should execute this. Null means "whichever account the
            // authenticated executor is bound to".
            $table->foreignId('broker_account_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            // Link to the trade this command acts on (close/modify). Null for open.
            $table->foreignId('trade_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            $table->enum('type', [
                'open',       // open a new position
                'close',      // close a position, fully or partially
                'modify',     // move SL/TP on an existing position
                'close_all',  // emergency flatten
                'start',      // resume trading (mirrors bot_settings.is_active)
                'stop',       // halt new entries, leave positions open
            ]);

            // Type-specific arguments: symbol, direction, volume, sl, tp, ticket, ...
            $table->json('payload')->nullable();

            $table->enum('status', [
                'pending',   // waiting to be claimed
                'claimed',   // an executor has taken it and is working
                'done',      // executed successfully
                'failed',    // executor reported a terminal failure
                'expired',   // not claimed before expires_at; deliberately not run
            ])->default('pending');

            // Collapses duplicate enqueues of the same intent. See class docblock.
            $table->string('idempotency_key', 64)->unique();

            // How many times an executor has claimed this. Guards against a command
            // that crashes its executor being retried forever.
            $table->unsignedTinyInteger('attempts')->default(0);

            // What the broker actually did: ticket, fill price, slippage, retcode.
            $table->json('result')->nullable();

            // Human-readable failure, already mapped from the MT5 retcode.
            $table->text('error')->nullable();

            // A market order that sat in the queue for ten minutes is no longer the
            // trade the strategy intended. Past this point it is expired, not run.
            $table->timestamp('expires_at')->nullable();

            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->timestamps();

            // The executor's polling query: pending commands for this account, oldest first.
            $table->index(['broker_account_id', 'status', 'id']);
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trade_commands');
    }
};
