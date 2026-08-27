<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `bot_logs` never had an owner, and `/logs` never filtered by one.
 *
 * That was correct while there was one operator. With tenants it means every customer
 * reads every other customer's executor output - retcodes, symbols, rejected orders, the
 * shape of somebody else's trading - and the page's own controls made it worse: deleting a
 * row took an id and checked nothing, and "Clear all" truncated the table for the entire
 * platform.
 *
 * The column is nullable rather than required, because rows already in the table cannot
 * all be attributed and inventing an owner for them would be worse than admitting there
 * isn't one. Backfill runs in three passes, most reliable first:
 *
 * 1. Through `related_trade_id`, which carries a real owner.
 * 2. Through `context->alert_id`, which the monitor stamps on every incident it records.
 *    Worth doing before the account pass rather than after: monitor rows are the most
 *    numerous kind on a deployment that has been running unattended, and they carry no
 *    account at all - so without this they would all orphan.
 * 3. Through `context->broker_account_id`, which the EA stamps on everything it writes.
 * 4. If the database holds exactly one user, everything remaining is theirs - the same
 *    unambiguous-case rule the `is_admin` migration used.
 *
 * Anything still unattributed after that stays null and is visible to nobody, which is the
 * safe direction for a leak: rows disappear from a page rather than appearing on a
 * stranger's.
 *
 * Every statement here is portable between MySQL and SQLite on purpose. Production is
 * MySQL and the test suite is SQLite in memory, so a migration written in one dialect is a
 * migration the test suite cannot execute - which would leave exactly this class of change
 * untested.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bot_logs', function (Blueprint $table) {
            $table->foreignId('user_id')
                ->nullable()
                ->after('id')
                ->constrained()
                ->cascadeOnDelete();

            // The page's own query: this tenant's logs, newest first.
            $table->index(['user_id', 'created_at'], 'bot_logs_owner_recent');
        });

        $this->attributeThroughTrades();
        $this->attributeThroughContext('alert_id', 'alerts');
        $this->attributeThroughContext('broker_account_id', 'broker_accounts');
        $this->attributeRemainderIfUnambiguous();
    }

    /**
     * Pass 1 - the trade knows who it belongs to.
     *
     * A correlated subquery rather than an UPDATE ... JOIN, which SQLite has no syntax for.
     * A log whose trade has since been deleted resolves to null and falls through to the
     * later passes, which is correct: it is genuinely unattributable this way.
     */
    private function attributeThroughTrades(): void
    {
        DB::table('bot_logs')
            ->whereNull('user_id')
            ->whereNotNull('related_trade_id')
            ->update([
                'user_id' => DB::raw('(select user_id from trades where trades.id = bot_logs.related_trade_id)'),
            ]);
    }

    /**
     * Attribute through an owning record whose id the writer stamped into the context blob.
     *
     * Done in PHP rather than SQL because the JSON functions differ between MySQL and
     * SQLite, and because both lookup tables are small enough to hold entirely in memory:
     * one row per terminal, and one row per incident, rather than one per log line.
     */
    private function attributeThroughContext(string $key, string $table): void
    {
        $owners = DB::table($table)->pluck('user_id', 'id');

        if ($owners->isEmpty()) {
            return;
        }

        DB::table('bot_logs')
            ->whereNull('user_id')
            ->whereNotNull('context')
            ->orderBy('id')
            ->chunkById(500, function ($rows) use ($owners, $key) {
                foreach ($rows as $row) {
                    $context = json_decode((string) $row->context, true);

                    if (! is_array($context) || ! isset($context[$key])) {
                        continue;
                    }

                    $owner = $owners->get((int) $context[$key]);

                    if ($owner === null) {
                        continue;
                    }

                    DB::table('bot_logs')->where('id', $row->id)->update(['user_id' => $owner]);
                }
            });
    }

    /**
     * Pass 3 - one user in the database means there is nobody else it could belong to.
     */
    private function attributeRemainderIfUnambiguous(): void
    {
        if (DB::table('users')->count() !== 1) {
            return;
        }

        DB::table('bot_logs')
            ->whereNull('user_id')
            ->update(['user_id' => (int) DB::table('users')->value('id')]);
    }

    public function down(): void
    {
        Schema::table('bot_logs', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->dropIndex('bot_logs_owner_recent');
            $table->dropColumn('user_id');
        });
    }
};
