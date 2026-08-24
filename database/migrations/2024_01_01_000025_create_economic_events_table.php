<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Economic calendar events.
 *
 * `bot_settings.news_filter_enabled` has existed since the first migration, defaults to
 * true, and is shown as an enabled toggle on the settings page - and nothing has ever
 * enforced it, because there was no calendar to enforce it against. A risk control that
 * is advertised and absent is worse than one that was never offered: it gets budgeted for.
 * This table is what makes that setting mean what it already claims.
 *
 * ## Shape
 *
 * One row per scheduled release. `external_id` is a hash of the fields that identify an
 * event on the source feed, so re-fetching the same week updates rows rather than
 * duplicating them - the feed is refetched hourly to pick up `actual` values as they
 * print, and forecasts are revised in place.
 *
 * Times are stored UTC. The source publishes an ISO 8601 timestamp with an offset; it is
 * normalised on the way in so nothing downstream has to think about it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('economic_events', function (Blueprint $table) {
            $table->id();

            // Identity on the source feed. Hashed from title + currency + scheduled time
            // rather than trusting an upstream id, because the feed publishes none.
            $table->string('external_id', 64)->unique();

            $table->string('title');
            $table->string('currency', 8)->index();

            // high / medium / low / holiday. Only `high` gates trading; the rest are shown
            // for context and deliberately do not stop anything.
            $table->string('impact', 16)->index();

            $table->timestamp('scheduled_at')->index();

            // Free text on purpose: the feed publishes "3.2%", "-0.4%", "$52.3B" and "".
            // Parsing these into numbers means guessing at units for display value only.
            $table->string('actual', 32)->nullable();
            $table->string('forecast', 32)->nullable();
            $table->string('previous', 32)->nullable();

            // useCurrent() for the same reason the alerts table needs it: MySQL refuses a
            // second non-nullable TIMESTAMP in one table without an explicit default.
            // The value is always written by the feed; this only satisfies the DDL.
            $table->timestamp('fetched_at')->useCurrent();
            $table->timestamps();

            // The blackout check asks "any high-impact event for these currencies near
            // this moment", which is exactly this composite.
            $table->index(['impact', 'scheduled_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('economic_events');
    }
};
