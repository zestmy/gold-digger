<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Record Where a Trade Came From
 *
 * Reconciliation adopts positions it finds on the terminal that the dashboard has no row
 * for - opened by hand, or opened by the bot while it could not report. Those rows have to
 * be distinguishable from the ones this system opened, because `TradeManager` acts on
 * `trades`.
 *
 * Without this flag, adopting a position a person opened manually would hand it straight to
 * the take-profit ladder: `max_holding_bars` would close it after 24 bars, and a reversal
 * would close it outright. Closing someone's manual position because it appeared in a table
 * is the worst thing this feature could do, so the flag is not a nicety.
 *
 * `origin` rather than a boolean `is_managed`, because the two questions differ: whether
 * something may be managed is a policy that can change, where the position came from is a
 * fact that does not.
 *
 * ## sl_price becomes nullable
 *
 * It was NOT NULL by deliberate choice - "a position with no stop is a bug worth failing
 * loudly on" - and that reasoning holds for every position *this system opens*. It does not
 * hold for one found on the terminal: a manually opened position may genuinely have no stop,
 * and MT5 reports that as 0.0. Storing the zero would chart a stop at price zero, which is
 * worse than recording that there is none. Refusing to adopt it instead would leave a live
 * position invisible to the dashboard, which is worse again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trades', function (Blueprint $table) {
            $table->enum('origin', [
                'bot',      // opened from a signal this system generated
                'adopted',  // found on the terminal and taken into the table
            ])->default('bot')->after('magic_number');

            $table->decimal('sl_price', 12, 5)->nullable()->change();
        });

        Schema::table('trades', function (Blueprint $table) {
            // Reconciliation's hot query: "which of this account's trades do I believe are
            // still open", run against every position snapshot.
            $table->index(['broker_account_id', 'status'], 'trades_account_status_index');
        });
    }

    public function down(): void
    {
        Schema::table('trades', function (Blueprint $table) {
            $table->dropIndex('trades_account_status_index');
            $table->dropColumn('origin');
            $table->decimal('sl_price', 12, 5)->nullable(false)->change();
        });
    }
};
