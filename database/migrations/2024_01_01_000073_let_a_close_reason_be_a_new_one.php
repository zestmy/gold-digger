<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `rollover_exit`, and the last close-reason enum.
 *
 * The rollover flatten needs a reason of its own: recorded as `manual` it would be
 * indistinguishable from somebody closing a position by hand, and the backtester - which
 * reports `rollover_exit` in its exit breakdown - would be speaking a vocabulary the live
 * system could not answer in. Matching vocabularies is the whole point of the exit
 * breakdown existing on both sides.
 *
 * ## Why the column stops being an enum
 *
 * The same reasoning `trade_commands.type` and `trades.origin` were converted under, in
 * 000031: an enum of seven values means rewriting the column on every deployment that ever
 * adds an eighth, and the constraint was never what enforced anything. `FillController`
 * already holds the list a reported fill is checked against, and already has the better
 * answer for a reason outside it - flatten to `manual`, keep the original verbatim in the
 * note - because losing a fill over an unrecognised word would be worse than recording it
 * imprecisely.
 *
 * `trade_screenshots.close_reason` is deliberately left alone: nothing has ever written a
 * trade screenshot, and widening a column for a feature that does not exist is churn.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trade_partials', function (Blueprint $table) {
            $table->string('close_reason', 24)->change();
        });
    }

    public function down(): void
    {
        Schema::table('trade_partials', function (Blueprint $table) {
            $table->enum('close_reason', [
                'tp1',
                'tp2',
                'tp3',
                'sl',
                'reversal_exit',
                'time_exit',
                'manual',
            ])->change();
        });
    }
};
