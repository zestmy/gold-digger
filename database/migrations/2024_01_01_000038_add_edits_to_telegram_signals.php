<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Providers correct their own messages, and Telegram lets them.
 *
 * ## Why an edit is not a re-delivery
 *
 * Ingest is idempotent on `external_id`, which is what stops a retry becoming a second
 * position. An edit arrives with the same identity and different content, so that same
 * mechanism quietly re-parsed it - and a signal whose entry had been corrected from 2650
 * to 2680 would have had its stored levels rewritten underneath a position already open
 * at the old ones.
 *
 * Nothing traded twice, because execution is guarded separately. What was lost was the
 * truth: the analytics would have graded a trade against levels it was never taken at.
 *
 * ## The two cases are genuinely different
 *
 * Edited before anything was done: the corrected message is simply the message, and it
 * should be parsed and reviewed as if it had arrived that way.
 *
 * Edited after a position is open: the levels that matter are the ones the order carries,
 * and they cannot be un-sent. The record keeps what was traded, stores what the provider
 * now says, and says so out loud - because a provider correcting a signal you are already
 * in is a thing to look at, not a database update.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('telegram_signals', function (Blueprint $table) {
            $table->timestamp('edited_at')->nullable()->after('posted_at');

            // Counted rather than flagged. A provider who edits every signal twice is
            // telling you something about their process that a boolean cannot.
            $table->unsignedSmallInteger('edit_count')->default(0)->after('edited_at');

            // The message as first posted, kept only once an edit arrives. This is what
            // lets "what did we actually act on" survive the provider changing their mind.
            $table->text('original_text')->nullable()->after('raw_text');
        });
    }

    public function down(): void
    {
        Schema::table('telegram_signals', function (Blueprint $table) {
            $table->dropColumn(['edited_at', 'edit_count', 'original_text']);
        });
    }
};
