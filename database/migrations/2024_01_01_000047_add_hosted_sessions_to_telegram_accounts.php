<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lets the platform sign a tenant in and keep the session, instead of the tenant
 * running a collector on a machine of their own.
 *
 * The old arrangement kept the session where the reading happened, which is the safer
 * place for it and the reason it was built that way. It also meant onboarding an account
 * required Python, a file on disk and an application registered at my.telegram.org - five
 * steps before the product did anything. That is survivable for one operator and fatal
 * for a signup funnel.
 *
 * So the session moves here, and the honesty has to move with it: this column can read
 * every chat on a tenant's account and post as them. It is encrypted with APP_KEY so the
 * database alone is not enough, and it is never selected into a response that reaches a
 * browser.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('telegram_accounts', function (Blueprint $table) {
            // Telethon's StringSession. Encrypted by the model, so what lands here is
            // ciphertext and a dump of this table is not a set of usable logins.
            $table->text('session')->nullable()->after('bot_token_id');

            // Who runs the collector for this account. Defaulting to true is the SaaS
            // path; existing rows are corrected below, because they are already running
            // somewhere and this migration must not claim otherwise.
            $table->boolean('is_hosted')->default(true)->after('session');

            // Per-channel checkpoints, previously state.json next to the collector. A
            // hosted worker has nowhere to put a file that survives a redeploy, and this
            // is what stops a restart re-sending the last twenty messages of every chat.
            $table->json('ingest_state')->nullable()->after('is_hosted');

            $table->index(['is_hosted', 'login_state']);
        });

        // Anything that existed before this migration is signed in on somebody's own
        // machine. Marking it hosted would have the worker try to sign in a second time,
        // and Telegram treats a second session as exactly what it is.
        DB::table('telegram_accounts')->update(['is_hosted' => false]);
    }

    public function down(): void
    {
        Schema::table('telegram_accounts', function (Blueprint $table) {
            $table->dropIndex(['is_hosted', 'login_state']);
            $table->dropColumn(['session', 'is_hosted', 'ingest_state']);
        });
    }
};
