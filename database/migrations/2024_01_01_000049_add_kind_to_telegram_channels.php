<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What sort of chat a row is, now that it is no longer always a channel.
 *
 * `source` already says how a chat is read - Bot API or a user account - which is a
 * different question from what it is. A signal delivered by a bot in a private chat and
 * one posted in a broadcast channel arrive by the same transport and need telling apart
 * on screen, because the trust you extend them is not the same.
 *
 * Existing rows become 'channel'. That is what they were when they were registered, and
 * the next announce refreshes any that were really groups.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('telegram_channels', function (Blueprint $table) {
            // channel / group / bot / user
            $table->string('kind', 16)->default('channel')->after('source')->index();
        });
    }

    public function down(): void
    {
        Schema::table('telegram_channels', function (Blueprint $table) {
            $table->dropIndex(['kind']);
            $table->dropColumn('kind');
        });
    }
};
