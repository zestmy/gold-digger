<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Trade Screenshots Migration
 *
 * Stores chart screenshots captured at key trade events.
 * Screenshots help with:
 * - Post-trade review: "Why did this trade fail?"
 * - Pattern recognition: "What did the chart look like at entry?"
 * - Learning: Review winning vs losing trade setups
 *
 * WHY separate table?
 * - Multiple screenshots per trade (entry, TP1, TP2, exit)
 * - Large file paths don't clutter the trades table
 * - Easy to query "all screenshots for trade X" with proper ordering
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trade_screenshots', function (Blueprint $table) {
            $table->id();

            // Parent trade - cascade delete removes screenshots with trade
            $table->foreignId('trade_id')
                ->constrained()
                ->cascadeOnDelete();

            // What event triggered this screenshot
            $table->enum('screenshot_type', [
                'entry',         // Chart at trade entry
                'tp1_hit',       // Chart when TP1 was hit
                'tp2_hit',       // Chart when TP2 was hit
                'tp3_hit',       // Chart when TP3 was hit
                'sl_hit',        // Chart when stop loss was hit
                'reversal_exit', // Chart showing reversal signal
                'time_exit',     // Chart at forced time-based exit
                'manual_review', // Manually captured for review
            ]);

            // Path to file in storage (relative to storage/app/public)
            // Example: "screenshots/2024/01/trade_123_entry.png"
            $table->string('file_path');

            // File size for storage management
            $table->integer('file_size_kb');

            // Which timeframe was captured (e.g., "M5", "H1")
            $table->string('timeframe', 10)->nullable();

            // Price at moment of capture - useful for verification
            $table->decimal('price_at_capture', 12, 5);

            // Optional notes about what's notable in this screenshot
            $table->text('notes')->nullable();

            // When the screenshot was captured
            $table->timestamp('captured_at');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trade_screenshots');
    }
};
