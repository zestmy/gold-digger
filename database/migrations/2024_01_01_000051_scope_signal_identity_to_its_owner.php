<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The same Telegram message, received by two tenants, is two signals.
 *
 * `external_id` is the identity of a message on its source, and making it globally unique
 * was right while there was one trader. With customers it means the second tenant to
 * receive a message from a shared channel collides with the first: `record()` finds the
 * existing row, concludes this is a retry or an edit, and the second tenant never gets the
 * signal at all - silently, and only for channels more than one of them subscribes to,
 * which is to say the popular ones.
 *
 * The format is deliberately unchanged. Re-keying would make every already-recorded
 * message look new, and the worker back-fills every enabled channel on its first restart -
 * so a format change would replay a batch of signals into a live copier. Widening the
 * index costs nothing and risks nothing; rewriting the ids could open positions.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('telegram_signals', function (Blueprint $table) {
            $table->dropUnique(['external_id']);
            $table->unique(['user_id', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::table('telegram_signals', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'external_id']);
            $table->unique(['external_id']);
        });
    }
};
