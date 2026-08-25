<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where the person reading the dashboard actually is.
 *
 * ## Storage stays UTC, always
 *
 * This is a display preference and nothing else. Every timestamp in this application is
 * written in UTC and stays that way - a trading system whose stored times shift with a
 * user setting cannot be reasoned about at all, and the bugs it produces are the kind that
 * only appear twice a year when the clocks change.
 *
 * So nothing reads this column except rendering. It is the last thing applied, on the way
 * to the screen.
 *
 * ## Why it is nullable rather than defaulted
 *
 * Null means "not set", which is honestly different from "UTC". Somebody who has genuinely
 * chosen UTC and somebody who has never opened the setting should not be indistinguishable,
 * because only one of them wants to be prompted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // An IANA identifier - 'Asia/Kuala_Lumpur', not '+08:00'. A fixed offset is
            // wrong for half the year anywhere that observes daylight saving, and this
            // application already learned that lesson from the broker's server clock.
            $table->string('timezone', 64)->nullable()->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('timezone');
        });
    }
};
