<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the terminal is actually carrying.
 *
 * `resolved_symbol` holds one instrument and always did, because the EA carried one. Now
 * that it carries a list, the single field still means the primary - so nothing that reads
 * it changes - and this holds the rest.
 *
 * Worth storing rather than inferring: the dashboard can generate a signal for any symbol
 * it has candles for, but only the terminal knows which ones it will accept an order on.
 * Without this the two can disagree silently, and the way that surfaces is an order
 * refused hours later for an instrument nobody realised was missing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bot_heartbeats', function (Blueprint $table) {
            // [{"base": "XAUUSD", "resolved": "XAUUSDm"}, ...] - both spellings, because
            // the dashboard names instruments the way it stores them and the broker may
            // quote a suffixed variant.
            $table->json('symbols')->nullable()->after('resolved_symbol');
        });
    }

    public function down(): void
    {
        Schema::table('bot_heartbeats', function (Blueprint $table) {
            $table->dropColumn('symbols');
        });
    }
};
