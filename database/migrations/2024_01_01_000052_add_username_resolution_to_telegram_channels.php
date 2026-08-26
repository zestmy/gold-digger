<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Watching a private chat that was never announced.
 *
 * `announce()` reports channels, groups and bots, and deliberately not people - a
 * tenant's private correspondents are not something to inventory into a database somebody
 * else operates. That leaves a real gap: some providers deliver by direct message, and
 * there is no way to name one.
 *
 * So a tenant names it themselves. The row is created here holding only the username,
 * because the dashboard cannot turn `@someone` into a chat id - only a signed-in Telegram
 * client can. It sits pending until the collector resolves it, at which point `chat_id`
 * stops being a placeholder and becomes the real thing.
 *
 * Pending rows are keyed per user like everything else on this table now, so two tenants
 * naming the same provider get one row each rather than a collision.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('telegram_channels', function (Blueprint $table) {
            // null once resolved, so the common case costs nothing to query.
            // pending / failed
            $table->string('resolve_state', 16)->nullable()->after('username')->index();

            // Telegram's own words. "No user has that username" and "the account is
            // deactivated" need different responses from the person who typed it.
            $table->string('resolve_error')->nullable()->after('resolve_state');
        });
    }

    public function down(): void
    {
        Schema::table('telegram_channels', function (Blueprint $table) {
            $table->dropIndex(['resolve_state']);
            $table->dropColumn(['resolve_state', 'resolve_error']);
        });
    }
};
