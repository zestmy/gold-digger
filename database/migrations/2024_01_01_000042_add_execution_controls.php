<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Three controls over how a copied signal reaches the broker.
 *
 * ## Closing on an opposite signal
 *
 * A provider who posts SELL gold while you hold their BUY gold has changed their mind, and
 * the position taken on their earlier view is now held on nobody's. Off by default, because
 * it is a real behaviour change: a provider who hedges deliberately would find their two
 * positions collapsing into none.
 *
 * ## Spread in the stop distance
 *
 * A buy fills at the ask and its stop is hit on the bid, so the distance the market has to
 * travel is the published stop minus the spread. On gold at two points that is a
 * meaningful share of a five-point stop, and it makes the realised loss larger than the one
 * that was sized.
 *
 * Adding the spread to the distance used for sizing makes the position smaller, so the loss
 * when it comes is the one intended. It is deliberately not applied by widening the stop
 * itself, which would risk more rather than less.
 *
 * ## Which end of an entry zone
 *
 * "Entry: 4633 - 4637" names a range. Taking the end nearest the market fills soonest;
 * taking the end furthest away fills at the better price and sometimes not at all. Per
 * channel, because it is a judgement about how that provider writes their zones.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bot_settings', function (Blueprint $table) {
            $table->boolean('copier_close_on_opposite')->default(false)->after('copier_trail_distance_r');
            $table->boolean('copier_spread_buffer')->default(false)->after('copier_close_on_opposite');
        });

        Schema::table('telegram_channels', function (Blueprint $table) {
            // 'best' (the price furthest from market, the default and what this always
            // did), 'near' (fills soonest), 'average' (the midpoint).
            $table->string('entry_preference', 16)->nullable()->after('copier_levels');
        });
    }

    public function down(): void
    {
        Schema::table('bot_settings', function (Blueprint $table) {
            $table->dropColumn(['copier_close_on_opposite', 'copier_spread_buffer']);
        });

        Schema::table('telegram_channels', function (Blueprint $table) {
            $table->dropColumn('entry_preference');
        });
    }
};
