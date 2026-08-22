<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Trailing Stop and a Real Break-Even
 *
 * Two changes to how a position's stop behaves once it is winning.
 *
 * ## The stop moves once, and then never again
 *
 * `TradeManager` moves the stop to entry after TP1 fills and leaves it there. Everything after
 * that is either the final target or a return to break-even - a position that ran 150 pips and
 * came back gave all of it up. A trailing stop is the difference between capturing a move and
 * merely surviving it.
 *
 * Configured in pips rather than as an ATR multiple, unlike the initial stop. The ATR at entry
 * is not carried on `trades` - only `signals.features` records it - so an ATR trail would have
 * to re-derive volatility from whatever the market is doing now, which is a different quantity
 * from the one that sized the original stop. Pips are honest about what they are.
 *
 * Null on either column disables trailing, which is the default: this changes P&L, and a
 * setting that changes P&L should not arrive switched on.
 *
 * ## Break-even that actually breaks even
 *
 * Moving the stop to the entry price leaves the trade losing whatever it paid to get there -
 * spread crossed on entry, commission both sides, and any slippage. On a gold scalp those are a
 * meaningful share of a 30-pip first target. `breakeven_offset_pips` moves the stop that much
 * further into profit, so "break-even" means what the word says.
 *
 * Defaults to zero, preserving exactly the current behaviour for anyone who does not set it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('strategies', function (Blueprint $table) {
            // Profit, in pips, at which the stop starts following price. Null disables it.
            $table->decimal('trail_trigger_pips', 8, 2)->nullable()->after('sl_atr_multiplier');

            // How far behind the best price seen the stop sits. Null disables it.
            $table->decimal('trail_distance_pips', 8, 2)->nullable()->after('trail_trigger_pips');

            // How far past entry the break-even stop goes, to cover the cost of the round trip.
            $table->decimal('breakeven_offset_pips', 8, 2)->default(0)->after('trail_distance_pips');
        });
    }

    public function down(): void
    {
        Schema::table('strategies', function (Blueprint $table) {
            $table->dropColumn(['trail_trigger_pips', 'trail_distance_pips', 'breakeven_offset_pips']);
        });
    }
};
