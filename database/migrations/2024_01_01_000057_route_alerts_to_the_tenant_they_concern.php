<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Alerts were raised per tenant and delivered to one address.
 *
 * `HealthMonitor` has always swept every user and opened an incident against the one it
 * concerns. `AlertNotifier` then sent all of them to a single `TELEGRAM_CHAT_ID` from the
 * environment. Two consequences, both bad in a product people pay for:
 *
 * - A customer whose executor stops beating at 3am is never told. Monitoring an unattended
 *   system is most of what this application is for, so that is the product failing rather
 *   than a notification being missed.
 * - The operator receives every customer's incidents interleaved, with nothing in the
 *   message saying whose bot it was.
 *
 * So the destination moves onto the user. The environment variable keeps working and
 * changes meaning: it is now the platform's own address, used for incidents that belong to
 * no tenant, and as the operator's own destination on a single-operator deployment.
 *
 * That last part is what the seed below does. On a database holding exactly one user, that
 * user is the operator, the configured chat is theirs, and copying it onto them means this
 * migration changes nothing about where their alerts arrive. Any other shape is ambiguous
 * and is left alone - a tenant who has not set an address falls back to email rather than
 * inheriting somebody else's chat.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Telegram chat ids are numeric but signed and wide, and group ids are
            // negative. A string avoids the whole question and is never arithmetic.
            $table->string('telegram_chat_id', 64)->nullable()->after('timezone');

            // Off means "record the incident, do not message me". The incident is still
            // written to bot_logs either way, because the record is the point.
            $table->boolean('alerts_enabled')->default(true)->after('telegram_chat_id');
        });

        $configured = (string) (config('alerts.telegram.chat_id') ?? '');

        if ($configured === '' || DB::table('users')->count() !== 1) {
            return;
        }

        DB::table('users')->update(['telegram_chat_id' => $configured]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['telegram_chat_id', 'alerts_enabled']);
        });
    }
};
