<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Trade Partials Migration
 *
 * Records each partial close of a trade. Our strategy uses progressive profit-taking:
 * - TP1: Close 50% of position
 * - TP2: Close 30% of remaining
 * - TP3: Close final 20%
 *
 * WHY separate table instead of columns on trades?
 * - Unknown number of partials (0 to many per trade)
 * - Each partial has its own execution details (price, timestamp, P&L)
 * - Enables detailed analysis: "How often does TP2 get hit after TP1?"
 * - Clean audit trail of exactly what closed and when
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trade_partials', function (Blueprint $table) {
            $table->id();

            // Parent trade - cascade delete when trade is removed
            $table->foreignId('trade_id')
                ->constrained()
                ->cascadeOnDelete();

            // MT5 deal ticket for this specific close transaction
            // Different from position ticket - this is the close order ticket
            $table->bigInteger('mt5_deal_ticket')->nullable()->unique();

            // How many lots were closed in this partial
            $table->decimal('closed_lot_size', 8, 4);

            // Actual execution price
            $table->decimal('close_price', 12, 5);

            // What triggered this partial close
            $table->enum('close_reason', [
                'tp1',           // Hit first take profit level
                'tp2',           // Hit second take profit level
                'tp3',           // Hit third take profit level
                'sl',            // Hit stop loss
                'reversal_exit', // Strategy detected trend reversal
                'time_exit',     // Max holding time exceeded
                'manual',        // Manually closed by user
            ]);

            // =====================================================
            // P&L FOR THIS PARTIAL
            // Each partial has its own P&L based on its close price
            // =====================================================

            // Profit in pips for this partial close
            $table->decimal('pips_profit', 10, 2);

            // Gross profit before costs
            $table->decimal('gross_money_profit', 10, 4);

            // Portion of commission allocated to this partial
            $table->decimal('commission_money', 10, 4)->default(0);

            // Portion of swap allocated to this partial
            $table->decimal('swap_money', 10, 4)->default(0);

            // Net profit after costs
            $table->decimal('net_money_profit', 10, 4);

            // When this partial was executed
            $table->timestamp('closed_at');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trade_partials');
    }
};
