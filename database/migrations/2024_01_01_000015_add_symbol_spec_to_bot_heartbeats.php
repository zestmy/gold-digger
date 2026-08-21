<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add Symbol Specification to Heartbeats
 *
 * The heartbeat already carries `resolved_symbol` because only the terminal knows what
 * the broker calls gold. These three columns extend that principle to the rest of the
 * symbol's contract specification, for the same reason: the dashboard cannot know them
 * and must not guess.
 *
 * WHY the dashboard needs them at all, when the EA converts pips itself:
 *
 * - `pip_size` turns a strategy's `tp1_pips` into the price level stored on the signal.
 *   The signal row is what the analytics pages chart, so those levels have to be real.
 *   The EA is still told pips and still computes the prices it actually submits - these
 *   are for display and analysis, and the terminal's numbers remain authoritative.
 *
 * - `pip_value_per_lot` is the whole of position sizing. Risking 1% over a 30-pip stop
 *   is a division by "what one pip of one lot is worth in the account currency", which
 *   depends on contract size, tick value and the account's currency - three things that
 *   live in the terminal. The EA computes it once from
 *   SYMBOL_TRADE_TICK_VALUE * (pip_size / SYMBOL_TRADE_TICK_SIZE) and reports the result.
 *
 * Left null, the strategy layer refuses to size a position and records the signal with
 * skip_reason "no_symbol_spec" rather than falling back to a hardcoded gold multiplier.
 * A wrong multiplier here does not fail loudly - it silently trades the wrong size.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bot_heartbeats', function (Blueprint $table) {
            // Price movement one pip represents, in the symbol's quote units.
            // Gold: 0.10 for the trader's pip, against a broker point of 0.01.
            $table->decimal('pip_size', 12, 5)->nullable()->after('resolved_symbol');

            // Quoted decimal places, so prices are rounded the way the broker states them.
            $table->unsignedTinyInteger('digits')->nullable()->after('pip_size');

            // Account-currency value of a one-pip move on one lot.
            $table->decimal('pip_value_per_lot', 12, 5)->nullable()->after('digits');
        });
    }

    public function down(): void
    {
        Schema::table('bot_heartbeats', function (Blueprint $table) {
            $table->dropColumn(['pip_size', 'digits', 'pip_value_per_lot']);
        });
    }
};
