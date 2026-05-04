<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bot Logs Migration
 *
 * Centralized logging for the trading bot. Stores both Laravel dashboard logs
 * and Python bot logs in a single queryable table.
 *
 * WHY database logging instead of file logs?
 * - Queryable: "Show me all errors from last hour"
 * - Dashboard display: Real-time log viewer in the web UI
 * - Correlation: Link logs to specific trades/signals
 * - Retention: Easy to implement log rotation via SQL
 *
 * WHY separate from Laravel's default logging?
 * - Trading-specific context (trade_id, signal_id links)
 * - Cross-system: Python bot writes here too via API
 * - User-facing: Designed for dashboard display, not just debugging
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bot_logs', function (Blueprint $table) {
            $table->id();

            // Standard log levels following PSR-3
            $table->enum('level', [
                'debug',    // Detailed debug info (high volume)
                'info',     // General information (trade opened, signal generated)
                'warning',  // Potential issues (high spread, approaching daily loss limit)
                'error',    // Errors that didn't stop execution (order rejected, retry)
                'critical', // Serious errors (MT5 connection lost, unhandled exception)
            ]);

            // Where the log came from
            // Examples: "python_bot", "laravel_dashboard", "mt5_connector", "strategy_engine"
            $table->string('source', 50);

            // Human-readable log message
            $table->text('message');

            // Additional context as JSON
            // Example: {"spread": 0.8, "max_allowed": 0.5, "symbol": "XAUUSD"}
            $table->json('context')->nullable();

            // =====================================================
            // RELATED ENTITIES
            // Link logs to trades/signals for correlation
            // nullOnDelete: Keep logs even if trade/signal is deleted
            // =====================================================

            $table->foreignId('related_trade_id')
                ->nullable()
                ->constrained('trades')
                ->nullOnDelete();

            $table->foreignId('related_signal_id')
                ->nullable()
                ->constrained('signals')
                ->nullOnDelete();

            $table->timestamps();

            // =====================================================
            // INDEXES
            // Optimized for dashboard log viewer queries
            // =====================================================

            // Filter by level and time (e.g., "recent errors")
            $table->index(['level', 'created_at']);

            // Filter by source (e.g., "Python bot logs only")
            $table->index('source');

            // Find all logs for a specific trade
            $table->index('related_trade_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bot_logs');
    }
};
