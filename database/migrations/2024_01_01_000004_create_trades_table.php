<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Trades Migration
 *
 * The core table tracking all trading activity. Each record represents
 * a complete trade lifecycle from signal to close.
 *
 * WHY track both pips and money?
 * - Pips: Broker-independent performance metric, useful for strategy optimization
 * - Money: Actual P&L considering lot size, essential for account tracking
 *
 * WHY separate cost columns (spread, commission, swap)?
 * - Transparency: See exactly where costs come from
 * - Analytics: Identify which costs hurt most (e.g., high spread during news)
 * - Broker comparison: Compare true costs across brokers
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trades', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->constrained()
                ->cascadeOnDelete();

            // Which strategy generated this trade
            $table->foreignId('strategy_id')
                ->constrained()
                ->cascadeOnDelete();

            // Which broker account executed this trade
            $table->foreignId('broker_account_id')
                ->constrained()
                ->cascadeOnDelete();

            // =====================================================
            // MT5 IDENTIFICATION
            // =====================================================

            // MT5's position ticket number - unique identifier from broker
            // Nullable because trade starts as 'pending' before MT5 execution
            $table->bigInteger('mt5_ticket')->nullable()->unique();

            // Magic number: identifies trades from our bot vs manual trades
            // Each strategy can have its own magic number for filtering
            $table->integer('magic_number')->nullable();

            // =====================================================
            // TRADE DETAILS
            // =====================================================

            $table->string('symbol', 20);

            $table->enum('direction', ['buy', 'sell']);

            // Position sizes in lots
            // initial_lot_size: what we opened with
            // remaining_lot_size: what's still open (decreases with partial closes)
            $table->decimal('initial_lot_size', 8, 4);
            $table->decimal('remaining_lot_size', 8, 4);

            // Price levels (5 decimal places for gold: 1234.56789)
            $table->decimal('entry_price', 12, 5);
            $table->decimal('sl_price', 12, 5);
            $table->decimal('tp1_price', 12, 5);
            $table->decimal('tp2_price', 12, 5);
            $table->decimal('tp3_price', 12, 5)->nullable();

            // =====================================================
            // COST TRACKING
            // Itemized costs for transparency and analysis
            // =====================================================

            // Spread at entry time (pips and money equivalent)
            // High spread = expensive entry, common during news events
            $table->decimal('entry_spread_pips', 6, 2)->nullable();
            $table->decimal('entry_spread_money', 10, 4)->nullable();

            // Commission charged by broker (typically per-lot)
            $table->decimal('commission_money', 10, 4)->default(0);

            // Swap (overnight holding cost) - accumulates if position held overnight
            $table->decimal('swap_money', 10, 4)->default(0);

            // Slippage: difference between requested and actual fill price
            // Positive = unfavorable slippage (filled worse than requested)
            $table->decimal('slippage_pips', 6, 2)->nullable();

            // =====================================================
            // P&L TRACKING
            // Separate gross and net for clear cost impact visibility
            // =====================================================

            // Gross P&L: raw profit before any costs
            $table->decimal('gross_pnl_pips', 10, 2)->default(0);
            $table->decimal('gross_pnl_money', 12, 2)->default(0);

            // Net P&L: final profit after spread, commission, swap
            // This is what actually hits the account
            $table->decimal('net_pnl_money', 12, 2)->default(0);

            // =====================================================
            // STATUS & METADATA
            // =====================================================

            $table->enum('status', [
                'pending',          // Signal received, waiting for MT5 execution
                'open',             // Position is live
                'partially_closed', // Some lots closed (hit TP1/TP2)
                'fully_closed',     // All lots closed (hit TP3 or manual)
                'stopped_out',      // Hit stop loss
                'cancelled',        // Signal cancelled before execution
            ])->default('pending');

            // Why the trade was closed (e.g., "TP1 hit", "Reversal exit", "Max bars reached")
            $table->string('closure_reason')->nullable();

            // Free-form notes for trade review
            $table->text('notes')->nullable();

            // Timestamps for trade lifecycle
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('closed_at')->nullable();

            $table->timestamps();

            // =====================================================
            // INDEXES
            // Optimized for common dashboard queries
            // =====================================================

            // Dashboard: "Show me open trades for this user"
            $table->index(['user_id', 'status']);

            // Analytics: "Trades opened in date range"
            $table->index('opened_at');

            // Strategy performance: "All trades for strategy X"
            $table->index(['strategy_id', 'opened_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trades');
    }
};
