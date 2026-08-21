<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Make Signal Target Prices Nullable
 *
 * All three target columns were NOT NULL. Two separate cases cannot honour that:
 *
 * - `strategies.tp3_pips` is nullable by design. A strategy that leaves it unset intends
 *   the remainder to run on a trailing stop, and recording its signals was impossible
 *   without inventing a third target.
 *
 * - Targets are configured in *pips*, so turning them into prices needs the symbol's pip
 *   size, which only the terminal knows and which arrives on the heartbeat. Before the
 *   first heartbeat - or against a terminal that never reports it - the levels are simply
 *   not knowable. That signal still needs recording, with skip_reason "no_symbol_spec",
 *   because "the bot generated nothing all morning" has to be explainable.
 *
 * This is the same correction already applied to `trades.tp1_price`/`tp2_price` in
 * 2024_01_01_000013, for the same reason: a fabricated target gets charted as though the
 * strategy had chosen it.
 *
 * sl_price stays NOT NULL deliberately, and does not have the pip problem: the stop is
 * derived from ATR, which is already in price units. It is computable whenever a setup
 * exists, and a signal with no stop is a bug worth failing loudly on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('signals', function (Blueprint $table) {
            $table->decimal('tp1_price', 12, 5)->nullable()->change();
            $table->decimal('tp2_price', 12, 5)->nullable()->change();
            $table->decimal('tp3_price', 12, 5)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('signals', function (Blueprint $table) {
            $table->decimal('tp1_price', 12, 5)->nullable(false)->change();
            $table->decimal('tp2_price', 12, 5)->nullable(false)->change();
            $table->decimal('tp3_price', 12, 5)->nullable(false)->change();
        });
    }
};
