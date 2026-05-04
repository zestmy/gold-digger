<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Strategies Migration
 *
 * Stores trading strategy configurations. Each strategy defines:
 * - Entry/exit rules (EMA crossover, ADX filter, ATR-based stops)
 * - Take profit levels (TP1, TP2, TP3 with partial close percentages)
 * - Timeframes for trend detection and entry
 *
 * WHY configurable strategies?
 * - Allows optimization without code changes
 * - A/B testing different parameter sets
 * - Future: multiple strategies running simultaneously
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('strategies', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->constrained()
                ->cascadeOnDelete();

            // Human-readable strategy name
            $table->string('name');

            // Trading symbol - default XAUUSD (gold vs USD)
            $table->string('symbol', 20)->default('XAUUSD');

            // =====================================================
            // TIMEFRAME SETTINGS
            // Multi-timeframe approach: use higher TF for trend, lower for entries
            // =====================================================

            // Entry timeframe - where we look for entry signals
            // M5 = 5-minute candles (good for scalping)
            $table->string('timeframe_entry', 10)->default('M5');

            // Trend timeframe - where we determine overall trend direction
            // H1 = 1-hour candles (filters out noise, confirms trend)
            $table->string('timeframe_trend', 10)->default('H1');

            // =====================================================
            // INDICATOR SETTINGS
            // EMA crossover for trend, ADX for trend strength, ATR for volatility
            // =====================================================

            // Exponential Moving Average periods
            // When fast EMA crosses above slow EMA = bullish signal
            $table->integer('ema_fast')->default(20);
            $table->integer('ema_slow')->default(50);

            // ADX threshold - only trade when trend strength > this value
            // ADX > 25 indicates a trending market (good for our strategy)
            $table->decimal('adx_threshold', 5, 2)->default(25.00);

            // ATR period for volatility measurement
            // Used for dynamic stop loss calculation
            $table->integer('atr_period')->default(14);

            // =====================================================
            // TAKE PROFIT LEVELS
            // Partial close strategy: lock in profits progressively
            // =====================================================

            // TP1: First profit target in pips
            // Close tp1_close_pct of position here
            $table->decimal('tp1_pips', 8, 2);
            $table->decimal('tp1_close_pct', 5, 2)->default(50.00);

            // TP2: Second profit target
            // Close tp2_close_pct of REMAINING position here
            $table->decimal('tp2_pips', 8, 2);
            $table->decimal('tp2_close_pct', 5, 2)->default(30.00);

            // TP3: Final profit target (optional)
            // Close remaining position here, or let it run with trailing stop
            $table->decimal('tp3_pips', 8, 2)->nullable();
            $table->decimal('tp3_close_pct', 5, 2)->default(20.00);

            // =====================================================
            // STOP LOSS & EXIT RULES
            // =====================================================

            // Stop loss as multiple of ATR
            // Example: 1.5 ATR means SL is placed 1.5 * ATR away from entry
            $table->decimal('sl_atr_multiplier', 5, 2)->default(1.50);

            // Exit immediately if trend reverses (EMA cross opposite direction)
            $table->boolean('exit_on_reversal')->default(true);

            // Maximum bars to hold position before forced exit
            // Prevents trades from dragging on too long
            // 24 bars on M5 = 2 hours maximum hold time
            $table->integer('max_holding_bars')->nullable()->default(24);

            // Strategy activation status
            $table->boolean('is_active')->default(false);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('strategies');
    }
};
