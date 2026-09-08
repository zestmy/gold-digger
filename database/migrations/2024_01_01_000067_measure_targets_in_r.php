<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Targets as multiples of the stop, not fixed pips.
 *
 * The stop has always been ATR-sized - a volatility-aware distance - while the targets
 * were fixed pips, so the reward the ladder offered swung with volatility and nobody had
 * chosen it. The first month of outcome tracking put a number on the consequence: the
 * strategy's signals reached their first target half the time, but that target sat about
 * 0.6R from entry against a 1R stop, and 50% at 0.6R loses money.
 *
 * `tp1_r`, `tp2_r`, `tp3_r` express each rung as a multiple of the stop distance. When
 * `tp1_r` is set the strategy is in R; the pip columns stay for strategies that want a
 * fixed distance, and are ignored otherwise.
 *
 * Every existing strategy is moved to 1R / 2R / 3R, which is the change the outcome data
 * asked for: the first rung now pays at least what a stop costs. Whether that improves
 * expectancy is what the same tracking will measure next.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('strategies', function (Blueprint $table) {
            $table->decimal('tp1_r', 5, 2)->nullable()->after('tp3_close_pct');
            $table->decimal('tp2_r', 5, 2)->nullable()->after('tp1_r');
            $table->decimal('tp3_r', 5, 2)->nullable()->after('tp2_r');
        });

        DB::table('strategies')->update(['tp1_r' => 1.00, 'tp2_r' => 2.00, 'tp3_r' => 3.00]);
    }

    public function down(): void
    {
        Schema::table('strategies', function (Blueprint $table) {
            $table->dropColumn(['tp1_r', 'tp2_r', 'tp3_r']);
        });
    }
};
