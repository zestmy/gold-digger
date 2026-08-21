<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Make TP1/TP2 Prices Nullable
 *
 * The original schema assumed every trade uses the full TP1/TP2/TP3 ladder, so both
 * columns were NOT NULL. That does not survive contact with a real executor:
 *
 * - A position may legitimately run on a trailing stop with no fixed target.
 * - The emergency "close all" path and manual entries have no ladder at all.
 * - Reconciling a position opened outside the bot (or before it restarted) means
 *   recording a trade whose targets are simply unknown.
 *
 * Forcing a value there would mean inventing price levels and then charting them as
 * though they were real. Nullable is the honest representation of "no target set".
 *
 * tp3_price was already nullable; sl_price stays NOT NULL deliberately, because a
 * position with no stop is a bug worth failing loudly on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trades', function (Blueprint $table) {
            $table->decimal('tp1_price', 12, 5)->nullable()->change();
            $table->decimal('tp2_price', 12, 5)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('trades', function (Blueprint $table) {
            $table->decimal('tp1_price', 12, 5)->nullable(false)->change();
            $table->decimal('tp2_price', 12, 5)->nullable(false)->change();
        });
    }
};
