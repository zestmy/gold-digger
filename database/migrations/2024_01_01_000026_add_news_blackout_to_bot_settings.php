<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * News Blackout Width
 *
 * How long either side of a scheduled release the bot stands aside.
 *
 * ## Why two columns rather than one
 *
 * The two sides are not the same risk. Before a release, the danger is that a position is
 * opened into a spread that is about to widen and a price that is about to gap - the entry
 * itself is the mistake. After it, the danger is that the first move reverses; the market
 * usually knows within a few minutes what it thinks, but the spread can stay wide longer than
 * the direction stays uncertain. Anyone who wants them equal sets them equal.
 *
 * ## Why fifteen minutes
 *
 * Long enough to cover the pre-release spread widening most brokers apply to gold, short
 * enough that a filter set to `high` impact on USD costs a handful of setups a week rather
 * than a session. It is a starting point to be measured, not a claim - which is precisely why
 * `php artisan backtest` applies the same window: see `docs/NEWS_FILTER.md`.
 *
 * ## Why not zero by default
 *
 * Because `news_filter_enabled` already defaults to true, and has since the settings table was
 * created. A default of zero would mean the switch stayed decorative for every existing user -
 * the exact bug this migration exists to end. Anyone who wants the previous behaviour turns
 * the switch off, and now that means something.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bot_settings', function (Blueprint $table) {
            $table->unsignedSmallInteger('news_blackout_before_minutes')
                ->default(15)
                ->after('news_filter_enabled');

            $table->unsignedSmallInteger('news_blackout_after_minutes')
                ->default(15)
                ->after('news_blackout_before_minutes');
        });
    }

    public function down(): void
    {
        Schema::table('bot_settings', function (Blueprint $table) {
            $table->dropColumn(['news_blackout_before_minutes', 'news_blackout_after_minutes']);
        });
    }
};
