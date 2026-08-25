<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * More than one Telegram account feeding the copier.
 *
 * ## Why an account is a row and not a setting
 *
 * One collector could only ever be one account, so "which account saw this channel" had no
 * answer and did not need one. With several - a personal account in some channels and a
 * second in others, or one per VPS - the question is asked constantly: why did this channel
 * stop reporting, which sign-in expired, which machine needs attention.
 *
 * ## What this page can and cannot do
 *
 * It registers an account and issues that collector its own token, which is everything the
 * dashboard side needs. It cannot perform the sign-in: MTProto authentication needs the
 * code Telegram sends to the phone and, where set, a second-factor password. Those are
 * entered at the collector, on the machine that will hold the session, and deliberately
 * never travel through here - a web form that collected them would put a credential capable
 * of reading every chat on the account into a server that has no reason to hold one.
 *
 * So the page issues the token and shows the exact command. The sign-in happens where the
 * session will live.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_accounts', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // What a person calls it. There is no reliable identifier until the collector
            // signs in and reports one, and a list of numbers is not navigable.
            $table->string('label');

            // Filled in by the collector once it knows, so the list is checkable against
            // the accounts a person actually has.
            $table->string('telegram_username', 64)->nullable();
            $table->string('display_name')->nullable();

            // Which token this account's collector authenticates with. Revoking it stops
            // that collector alone.
            $table->foreignId('bot_token_id')->nullable()->constrained()->nullOnDelete();

            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'last_seen_at']);
        });

        Schema::table('telegram_channels', function (Blueprint $table) {
            // Which account can see this channel. Nullable for everything captured before
            // there was more than one.
            $table->foreignId('telegram_account_id')->nullable()->after('user_id')
                ->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('telegram_channels', function (Blueprint $table) {
            $table->dropForeign(['telegram_account_id']);
            $table->dropColumn('telegram_account_id');
        });

        Schema::dropIfExists('telegram_accounts');
    }
};
