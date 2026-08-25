<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The AI trading fund.
 *
 * ## Why a fund rather than a flag
 *
 * AI-initiated trading cannot be backtested - there are no historical model opinions to
 * replay - so the usual guarantee this system offers, that a setting can be measured
 * before it costs anything, is unavailable. A cap is what replaces it: the damage a wrong
 * decision can do is bounded to a number chosen in advance, in the open, by a person.
 *
 * It behaves like a sub-account rather than a permission. `ai_capital_cap` is the fund's
 * size; AI positions are sized off what remains of it rather than off the account balance,
 * and its realised losses deplete it. When it reaches zero the AI stops, and it stops
 * without touching the rest of the account - which is the entire point of expressing this
 * as capital rather than as a switch.
 *
 * ## Deliberately off, and deliberately not defaulted to a number
 *
 * `ai_trading_enabled` defaults false and `ai_capital_cap` defaults null. A default cap
 * would be this system choosing how much of someone's money an unmeasurable feature may
 * lose, which is not a decision it is entitled to make. With no cap set, nothing runs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bot_settings', function (Blueprint $table) {
            $table->boolean('ai_trading_enabled')->default(false)->after('news_blackout_after_minutes');

            // Nullable on purpose: absent means "not configured", which is different from
            // zero and must not be read as one.
            $table->decimal('ai_capital_cap', 12, 2)->nullable()->after('ai_trading_enabled');

            // Percentage of the *remaining fund*, not of the account. As the fund is drawn
            // down the position sizes fall with it, so a losing run shrinks itself rather
            // than betting the same stake against a smaller pot.
            $table->decimal('ai_risk_percentage', 5, 2)->default(1.00)->after('ai_capital_cap');

            $table->unsignedSmallInteger('ai_max_concurrent_trades')->default(1)->after('ai_risk_percentage');
        });

        // 'ai' as a third origin. Which positions belong to the fund is the fact every
        // other question here depends on - what it has lost, what it has open, whether it
        // may open more - and a boolean would not survive a fourth kind of position.
        Schema::table('trades', function (Blueprint $table) {
            $table->string('origin', 16)->default('bot')->change();
        });
    }

    public function down(): void
    {
        Schema::table('bot_settings', function (Blueprint $table) {
            $table->dropColumn([
                'ai_trading_enabled',
                'ai_capital_cap',
                'ai_risk_percentage',
                'ai_max_concurrent_trades',
            ]);
        });
    }
};
