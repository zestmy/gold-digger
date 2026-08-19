<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bot Heartbeats Migration
 *
 * One row per executor, overwritten on every poll. This is what BotStatusCard reads
 * instead of its hardcoded $isOnline = false.
 *
 * WHY overwrite rather than append?
 * A heartbeat every few seconds would be millions of rows a month for a value nobody
 * queries historically. Point-in-time trading history already lives in daily_summaries.
 *
 * WHY record algo_trading_enabled?
 * It is the single most common reason a healthy-looking terminal executes nothing
 * (retcode 10027). Surfacing it on the dashboard turns a silent failure into a visible
 * one - the terminal is connected, so the heartbeat still arrives, but the flag is off.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bot_heartbeats', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('broker_account_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            // Which executor: "mql5_ea", "python_bot", "metaapi_adapter"
            $table->string('source', 50);

            // Executor build info, for debugging version mismatches
            $table->string('version', 32)->nullable();
            $table->integer('terminal_build')->nullable();

            // Terminal state at the moment of the heartbeat
            $table->boolean('algo_trading_enabled')->default(false);
            $table->boolean('broker_connected')->default(false);

            // The symbol name the executor actually resolved (XAUUSDm, XAUUSD.a, ...).
            // Worth persisting: it is the value the strategy layer must use.
            $table->string('resolved_symbol', 32)->nullable();

            // Account snapshot, so the dashboard need not query MT5
            $table->decimal('balance', 12, 2)->nullable();
            $table->decimal('equity', 12, 2)->nullable();
            $table->decimal('margin_free', 12, 2)->nullable();
            $table->unsignedSmallInteger('open_positions')->default(0);

            $table->timestamp('last_seen_at');

            $table->timestamps();

            // One row per executor per user; upserted on every poll.
            $table->unique(['user_id', 'source']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bot_heartbeats');
    }
};
