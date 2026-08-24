<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Market Events
 *
 * The economic calendar: scheduled releases that move price on their own schedule rather
 * than on the market's.
 *
 * ## Why this table has to exist before the filter does
 *
 * `bot_settings.news_filter_enabled` has been on the settings page, defaulted to true, since
 * the table was created - and nothing has ever read it. A user seeing that switch believes
 * the bot stands aside for NFP. It does not. The filter cannot be written without a calendar
 * to consult, and this is that calendar.
 *
 * ## Not scoped to a user
 *
 * CPI happens at the same instant for everybody. Scoping the calendar per user would mean one
 * import per user of identical rows, and a join on every signal to fetch them. What *is* per
 * user is the blackout width and whether the filter runs at all, and those live on
 * `bot_settings` where the rest of that user's risk preferences already are.
 *
 * ## Dedupe without an id from the source
 *
 * The feed publishes no stable identifier, so the natural key is the one thing that cannot
 * collide legitimately: a currency does not release two events of the same name at the same
 * instant. Re-importing the same week therefore updates rows rather than doubling them, which
 * matters because the importer runs on a schedule and the feed revises times as the week
 * moves.
 *
 * ## Why `actual` is here for a filter that never reads it
 *
 * The blackout only needs `scheduled_at`, `currency` and `impact`. `forecast` / `previous` /
 * `actual` cost nothing to store while the row is being written anyway, and they are what
 * turns "we skipped four setups on Friday" into "we skipped four setups around an NFP that
 * came in 80k over forecast". Discarding them means the question cannot be asked later, and
 * the feed does not serve history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('market_events', function (Blueprint $table) {
            $table->id();

            // Which importer wrote this. 'manual' is a first-class source: a row typed in by
            // hand blacks out its window exactly like an imported one, which is the fallback
            // when the feed is down and something big is known to be coming.
            $table->string('source', 32)->default('forexfactory');

            $table->string('title');

            // ISO currency the release concerns - USD, EUR, GBP. Gold is priced in dollars,
            // so USD is the one that matters here; the column is general because the column
            // being general costs nothing.
            $table->string('currency', 8);

            // The feed's own words, normalised to lower case. 'holiday' is not an impact
            // level but the feed reports it in the same field, and a market holiday is worth
            // keeping for the same reason a release is.
            $table->enum('impact', ['high', 'medium', 'low', 'holiday'])->default('low');

            // UTC, always. The feed publishes an offset per row and the importer resolves it;
            // storing the local time would make every comparison against a bar a conversion.
            $table->timestamp('scheduled_at');

            // As published. Strings, not numbers: the feed sends "0.3%", "375K", "<0.1%" and
            // occasionally an empty string, and coercing those to a decimal loses the ones
            // that are not decimals.
            $table->string('forecast', 32)->nullable();
            $table->string('previous', 32)->nullable();

            // Populated on a later import, once the release has happened. The forward-looking
            // feed leaves it empty.
            $table->string('actual', 32)->nullable();

            $table->timestamps();

            // The natural key. See the class comment: no stable id from the source.
            $table->unique(['currency', 'title', 'scheduled_at'], 'market_events_natural_key');

            // The only read the filter makes: events of interest in a window around a bar.
            $table->index(['scheduled_at', 'impact'], 'market_events_window_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('market_events');
    }
};
