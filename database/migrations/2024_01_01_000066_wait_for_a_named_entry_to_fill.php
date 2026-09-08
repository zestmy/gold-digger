<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A signal that names an entry away from the market is a pending order, and scoring it
 * from the moment it was posted - with the provider's entry as the reference - credited a
 * sell posted at 4,600 while price stood at 4,550 with an instant target and a favourable
 * "worst" excursion. The first day's numbers showed an average worst excursion above zero,
 * which no correctly scored signal can produce.
 *
 * `activated_at` is the bar on which price first reached the named entry; until then the
 * row waits, counting `wait_bars`, and a signal the market never came back to is
 * `unfilled` - a limit order that never filled, which is neither a win nor a loss.
 *
 * The existing rows are derived data scored under the old rule, so they are removed here
 * and re-opened by the next scheduled `signals:track` from the same bars. Nothing that
 * cannot be recomputed is lost.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('signal_outcomes', function (Blueprint $table) {
            $table->timestamp('activated_at')->nullable()->after('started_at');
            $table->unsignedInteger('wait_bars')->default(0)->after('bars_seen');
        });

        DB::table('signal_outcomes')->delete();
    }

    public function down(): void
    {
        Schema::table('signal_outcomes', function (Blueprint $table) {
            $table->dropColumn(['activated_at', 'wait_bars']);
        });
    }
};
