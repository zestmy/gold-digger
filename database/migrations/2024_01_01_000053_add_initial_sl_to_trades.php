<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Add Initial SL To Trades
 *
 * The stop this position opened with, kept for the life of the trade.
 *
 * WHY a second column when `sl_price` exists?
 * `sl_price` is live. `PositionReconciler` writes the terminal's actual stop back onto the
 * row every time the executor reports its positions, which is what makes the dashboard
 * agree with MT5 and what lets `PositionManager::improves()` refuse a stop that would sit
 * further away. It is the current stop, not the original one.
 *
 * WHY that matters:
 * `PositionManager` measures everything in R - "bank half at 1R", "trail 1R behind the
 * high" - and it was computing R as `entry_price - sl_price` on every pass. That is the
 * opening risk exactly once: before the first protective move. After it, R is the distance
 * to the stop the protection itself just set.
 *
 * The consequences were not subtle. A break-even move makes `sl_price` equal to
 * `entry_price`, so R becomes zero and the position drops out of management entirely on
 * the next pass - a configured trailing stop moves once and then freezes for the rest of
 * the trade. Where the trail lands past the entry instead, R is measured to a stop in
 * profit and the trail distance drifts away from the configured multiple with every move.
 *
 * Both are silent. The commands stop arriving, or arrive with the wrong level, and nothing
 * anywhere reports a fault.
 *
 * WHY nullable:
 * Positions opened before this column existed have no honest value for it, and inventing
 * one from their current stop would be recording the very number this exists to avoid.
 * `PositionManager` falls back to `sl_price` for those, which is what it did for all of
 * them until now.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trades', function (Blueprint $table) {
            $table->decimal('initial_sl_price', 12, 5)->nullable()->after('sl_price');
        });

        // Open positions have their opening stop in `sl_price` unless something has already
        // moved it. Nothing moves it today without this column existing, so for rows that
        // predate the deployment the two are the same number and backfilling is safe.
        //
        // Closed trades are left alone: their stop is whatever it was when they closed, and
        // no reading of it is the opening risk.
        Schema::hasTable('trades') && DB::table('trades')
            ->whereIn('status', ['open', 'partially_closed'])
            ->whereNull('initial_sl_price')
            ->update(['initial_sl_price' => DB::raw('sl_price')]);
    }

    public function down(): void
    {
        Schema::table('trades', function (Blueprint $table) {
            $table->dropColumn('initial_sl_price');
        });
    }
};
