<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Daily Summaries Migration
 *
 * Pre-aggregated daily statistics per user per broker account.
 * Think of this as a materialized view that's updated daily.
 *
 * WHY pre-aggregate instead of calculating on-the-fly?
 * - Performance: Dashboard loads instantly without scanning all trades
 * - Historical accuracy: Captures point-in-time balance snapshots
 * - Complex metrics: Drawdown calculations are expensive to compute live
 *
 * WHEN is this updated?
 * - By the Python bot at end of trading day
 * - By Laravel scheduled command as backup
 * - Can be recalculated for past dates if needed
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_summaries', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('broker_account_id')
                ->constrained()
                ->cascadeOnDelete();

            // The date this summary covers (just date, no time)
            $table->date('date');

            // =====================================================
            // TRADE COUNTS
            // =====================================================

            // Total trades closed on this day
            $table->integer('trades_count')->default(0);

            // Trades that ended in profit
            $table->integer('wins_count')->default(0);

            // Trades that ended in loss (includes stopped out)
            $table->integer('losses_count')->default(0);

            // =====================================================
            // P&L SUMMARY
            // =====================================================

            // Total gross P&L before costs
            $table->decimal('gross_pnl_money', 12, 2)->default(0);

            // Total costs (spread + commission + swap)
            $table->decimal('total_costs_money', 12, 2)->default(0);

            // Net P&L after all costs
            $table->decimal('net_pnl_money', 12, 2)->default(0);

            // =====================================================
            // RISK METRICS
            // =====================================================

            // Maximum drawdown during this trading day
            // Measured as peak-to-trough decline in account value
            $table->decimal('max_drawdown_money', 12, 2)->default(0);

            // =====================================================
            // BALANCE SNAPSHOTS
            // Point-in-time captures for equity curve charting
            // =====================================================

            // Account balance at start of trading day
            $table->decimal('starting_balance', 12, 2);

            // Account balance at end of trading day
            $table->decimal('ending_balance', 12, 2);

            $table->timestamps();

            // =====================================================
            // CONSTRAINTS
            // One summary per user per account per day
            // =====================================================
            $table->unique(['user_id', 'broker_account_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_summaries');
    }
};
