<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One heartbeat row per executor, where "executor" now includes the account it trades.
 *
 * `bot_heartbeats` was unique on (user_id, source), which encoded "one terminal per user".
 * Two executors under one user - two broker accounts, or two terminals on the same
 * account - therefore shared a row and took turns overwriting it: whichever polled last
 * owned `broker_account_id`, `resolved_symbol`, `pip_size` and `open_positions`, and the
 * strategy layer's lookup by account then found no row for the other one. That surfaced
 * as `no_account_snapshot` on every signal for whichever account had lost the race a
 * moment earlier, with nothing on the dashboard looking wrong.
 *
 * The key is now (user_id, broker_account_id, source). The heartbeat controller upserts on
 * all three, and every lookup that knows its account filters on it.
 *
 * ## Rows with no account
 *
 * `broker_account_id` is nullable: a token issued without an account binding still
 * heartbeats. MySQL (and SQLite, and Postgres) treat NULL as distinct from every other
 * NULL in a unique index, so any number of unbound rows per (user, source) are allowed and
 * none of them collide. Two unbound executors under one user therefore append rather than
 * overwrite - which is the correct reading, since nothing can tell them apart, and it is
 * still a handful of rows rather than a history. Bind the token to an account to get the
 * one-row-per-executor behaviour back.
 *
 * ## Order of operations
 *
 * The new index is added before the old one is dropped. `user_id` carries a foreign key,
 * and MySQL refuses to drop the last index that could serve it; the new unique begins with
 * `user_id`, so once it exists the old one is no longer needed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bot_heartbeats', function (Blueprint $table) {
            $table->unique(['user_id', 'broker_account_id', 'source'], 'bot_heartbeats_user_account_source_unique');
        });

        Schema::table('bot_heartbeats', function (Blueprint $table) {
            $table->dropUnique('bot_heartbeats_user_id_source_unique');
        });
    }

    public function down(): void
    {
        // Rows that only exist because the key widened would collide on the narrower one.
        // Keep the newest per (user, source) - the same row the old upsert would have held.
        $keep = DB::table('bot_heartbeats')
            ->selectRaw('MAX(id) as id')
            ->groupBy(['user_id', 'source'])
            ->pluck('id');

        DB::table('bot_heartbeats')->whereNotIn('id', $keep)->delete();

        Schema::table('bot_heartbeats', function (Blueprint $table) {
            $table->unique(['user_id', 'source'], 'bot_heartbeats_user_id_source_unique');
        });

        Schema::table('bot_heartbeats', function (Blueprint $table) {
            $table->dropUnique('bot_heartbeats_user_account_source_unique');
        });
    }
};
