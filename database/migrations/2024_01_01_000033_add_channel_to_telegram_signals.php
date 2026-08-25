<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tie each captured signal to the channel it came from.
 *
 * `chat_id` was already stored, so this is not new information - it is a join that
 * survives a channel being renamed, and one that an index can serve. Per-channel results
 * are the whole point of running more than one provider, and grouping a P&L query by a
 * free-text chat title would silently split a provider in two the day they change it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('telegram_signals', function (Blueprint $table) {
            // Nullable: messages from chats that were never registered as channels still
            // belong in the table, and a signal outliving its channel row is worth keeping.
            $table->foreignId('telegram_channel_id')->nullable()->after('chat_id')
                ->constrained()->nullOnDelete();
        });

        // Anything already captured keeps its history rather than starting from empty.
        // Written with the query builder rather than a joined UPDATE, which MySQL accepts
        // and SQLite does not - the test suite runs on the latter, and a migration that
        // only works on one of them fails where it is cheapest to notice and hardest to
        // debug.
        DB::table('telegram_channels')->orderBy('id')->each(function ($channel) {
            DB::table('telegram_signals')
                ->whereNull('telegram_channel_id')
                ->where('chat_id', $channel->chat_id)
                ->where('source', $channel->source)
                ->update(['telegram_channel_id' => $channel->id]);
        });
    }

    public function down(): void
    {
        Schema::table('telegram_signals', function (Blueprint $table) {
            $table->dropForeign(['telegram_channel_id']);
            $table->dropColumn('telegram_channel_id');
        });
    }
};
