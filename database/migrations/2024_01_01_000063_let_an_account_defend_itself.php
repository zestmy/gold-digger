<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two-factor authentication, on accounts that can move money.
 *
 * A session on this dashboard can enable autonomous trading, raise the AI capital cap,
 * disable the news filter and queue orders. It was protected by an email address and a
 * password, with no second factor, no list of where it was signed in, and no way to sign
 * everything else out - which is Breeze's default and entirely reasonable for the blog it
 * was written for.
 *
 * ## Everything here is encrypted or hashed
 *
 * `two_factor_secret` is the shared secret: anyone holding it can generate valid codes for
 * ever, so it is encrypted at rest exactly as broker account numbers and Telegram sessions
 * are. Losing `APP_KEY` therefore means every enrolled account has to enrol again - the same
 * trade already accepted elsewhere, and stated in DEPLOYMENT.md.
 *
 * Recovery codes are stored **hashed**, not encrypted. They are single-use passwords, so
 * they get the treatment passwords get: the server never needs to read one back, only to
 * check whether one matches. The consequence is that they are shown exactly once, at
 * enrolment, and cannot be recovered afterwards - which the page says plainly.
 *
 * ## Confirmed, not merely set
 *
 * `two_factor_confirmed_at` is separate from the secret because enrolment has two steps: the
 * secret is issued, and then a code from it is proved to work. Enforcing a second factor
 * that was never proved is how somebody locks themselves out of an account with a
 * mistyped setup - the secret exists but no authenticator holds it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('two_factor_secret')->nullable()->after('password');

            // Hashed, single-use. See above for why these are not encrypted.
            $table->text('two_factor_recovery_codes')->nullable()->after('two_factor_secret');

            // Null until a code from the secret has actually been proved to work.
            $table->timestamp('two_factor_confirmed_at')->nullable()->after('two_factor_recovery_codes');

            // The last timestep a code was accepted for. A TOTP code stays valid for its
            // whole window, so without remembering which one was used an intercepted code
            // can be replayed inside its own thirty seconds.
            $table->unsignedBigInteger('two_factor_last_step')->nullable()->after('two_factor_confirmed_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'two_factor_secret',
                'two_factor_recovery_codes',
                'two_factor_confirmed_at',
                'two_factor_last_step',
            ]);
        });
    }
};
