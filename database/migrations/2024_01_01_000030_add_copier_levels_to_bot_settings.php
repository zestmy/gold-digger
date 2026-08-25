<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whose stop and targets a copied signal trades with.
 *
 * 'provider' takes the levels as posted. 'strategy' keeps the provider's entry - which is
 * the part you are actually copying, their read on where to get in - and applies this
 * account's own stop and ladder to it.
 *
 * ## The argument each way
 *
 * The strategy's stop is `sl_atr_multiplier` times ATR: sized to what the instrument
 * actually does, consistent with every other position the account holds, and backtestable.
 * The provider's stop is a number that cannot be checked.
 *
 * Against that, a provider's stop may sit below a swing low or above a level, and ATR
 * knows nothing about structure. Replacing a structural stop with a volatility one can put
 * it somewhere that means nothing.
 *
 * Neither is obviously right, which is why this is a setting and why it defaults to
 * leaving the provider's levels alone - changing what a signal means is not something to
 * do by default.
 *
 * ## What does not change
 *
 * A message with no readable stop is still refused, even when the strategy would supply
 * one. The stop is the strongest evidence a message is a signal at all rather than
 * commentary, and a copier willing to invent levels for anything mentioning an instrument
 * would trade the provider's market commentary.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bot_settings', function (Blueprint $table) {
            $table->string('copier_levels', 16)->default('provider')->after('ai_max_concurrent_trades');
        });
    }

    public function down(): void
    {
        Schema::table('bot_settings', function (Blueprint $table) {
            $table->dropColumn('copier_levels');
        });
    }
};
