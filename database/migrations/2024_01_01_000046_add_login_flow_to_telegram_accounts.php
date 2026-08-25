<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Signing a Telegram account in from the dashboard.
 *
 * ## The problem with the previous arrangement
 *
 * It asked a person to open a terminal on another machine and run three Python commands.
 * That is a reasonable thing to ask a developer once and an unreasonable thing to ask
 * anybody every time they add an account, and it made a five-second task into a session.
 *
 * ## What changed, and what deliberately did not
 *
 * The phone number and the login code are now entered here. The session is still created
 * and kept by the collector, on the machine that will use it - the dashboard relays the
 * conversation and stores none of its outcome.
 *
 * That distinction is the whole point. A hosted copier that takes your phone number ends
 * up holding a session that can read every chat you have; this ends up holding a row that
 * says "signed in", while the credential itself lives where the reading happens.
 *
 * ## The secrets do pass through, and that is stated rather than hidden
 *
 * A code, and a two-step password when one is set, travel through this server on their way
 * to the collector. They are held in the cache with a short expiry and cleared the moment
 * they are collected, never written to a column, and never logged. That is better than
 * storing a session here and worse than typing them on the collector directly, which
 * remains available for anybody who prefers it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('telegram_accounts', function (Blueprint $table) {
            // idle / requested / code_sent / code_submitted / password_needed /
            // password_submitted / active / failed
            $table->string('login_state', 24)->default('idle')->after('display_name');

            // Kept so the page can say which number is being signed in, and so a stalled
            // attempt is identifiable. Not a secret, and not usable on its own.
            $table->string('login_phone', 32)->nullable()->after('login_state');

            // Whatever went wrong, in the words Telegram used. "Failed" alone sends people
            // to the wrong place - a wrong code and a banned number need different actions.
            $table->string('login_message')->nullable()->after('login_phone');

            $table->timestamp('login_updated_at')->nullable()->after('login_message');
        });
    }

    public function down(): void
    {
        Schema::table('telegram_accounts', function (Blueprint $table) {
            $table->dropColumn(['login_state', 'login_phone', 'login_message', 'login_updated_at']);
        });
    }
};
