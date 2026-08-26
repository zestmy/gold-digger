<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A chat belongs to the tenant watching it, not to whoever registered it first.
 *
 * `unique(source, chat_id)` was correct while this ran for one person. With customers it
 * means the first tenant to announce a popular signal channel owns the only row there can
 * ever be: everyone else's collector announces it, `register()` finds the existing row,
 * declines to change its owner - correctly, since widening permission is exactly what it
 * must never do - and the second tenant is left unable to enable a channel they are
 * plainly a member of, with nothing on screen explaining why.
 *
 * The same key also made `known` in the watch-list response every channel in the database
 * rather than the caller's, so one tenant's collector could read another's channel titles.
 * That is closed in CollectorController alongside this.
 *
 * Nothing is lost by widening the key: every existing row keeps its owner, and rows only
 * become able to coexist where they previously could not.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('telegram_channels', function (Blueprint $table) {
            $table->dropUnique(['source', 'chat_id']);
            $table->unique(['user_id', 'source', 'chat_id']);
        });
    }

    public function down(): void
    {
        Schema::table('telegram_channels', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'source', 'chat_id']);
            $table->unique(['source', 'chat_id']);
        });
    }
};
