<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add Broker Volume Limits to Heartbeats
 *
 * More symbol truth from the terminal, for the same reason as `pip_size` in 000015: the
 * dashboard cannot know these and must not guess them.
 *
 * Trade management is what needs them. Taking 50% of a 0.02-lot position leaves 0.01 on
 * each side and works; taking 50% of a 0.01-lot position asks the broker to close 0.005,
 * which is below every broker's minimum. `CGDExecutor::NormalizeVolume` snaps that to zero
 * and the close fails - so without these values a small account would generate a failing
 * partial-close command at every rung of the ladder, on every trade, for ever.
 *
 * Knowing the minimum lets `TradeManager` decline to build the ladder at all on a position
 * too small to divide, and leave it to run to its final target as a single unit. That is a
 * decision worth making deliberately rather than discovering from a queue full of errors.
 *
 * `volume_step` matters for the same reason one step down: a partial that is legal but not
 * a multiple of the step gets snapped *downward* by the executor, so the dashboard's idea
 * of what remains would drift from the broker's on every rung.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bot_heartbeats', function (Blueprint $table) {
            // Smallest position the broker will accept, in lots.
            $table->decimal('volume_min', 12, 4)->nullable()->after('pip_value_per_lot');

            // Granularity of any volume: every order must be a multiple of this.
            $table->decimal('volume_step', 12, 4)->nullable()->after('volume_min');
        });
    }

    public function down(): void
    {
        Schema::table('bot_heartbeats', function (Blueprint $table) {
            $table->dropColumn(['volume_min', 'volume_step']);
        });
    }
};
