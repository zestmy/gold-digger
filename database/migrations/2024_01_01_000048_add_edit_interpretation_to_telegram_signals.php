<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a provider's edit to an already-traded signal actually changed.
 *
 * Until now an edit on a live position produced one alert and stopped: "the order carries
 * the original levels and they cannot be un-sent. Check the position." True, and useless
 * at three in the morning - a provider tightening a stop from 2390 to 2380 and one
 * widening it to 2450 generated the identical sentence, and only one of those is a thing
 * you would act on.
 *
 * These columns hold the reading, not the acting. Nothing here moves a position; they
 * exist so the alert can say which of the two happened, and so the record shows what was
 * understood at the time rather than only that something changed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('telegram_signals', function (Blueprint $table) {
            // The same closed set the follow-up interpreter uses, so an edit and a reply
            // saying the same thing arrive downstream in the same shape.
            $table->string('edit_action', 32)->nullable()->after('edit_count');

            // reduced / increased / unchanged / unclear. Recorded separately from the
            // action because "they widened the stop" is worth alerting on even though the
            // correct response to it is to do nothing.
            $table->string('edit_risk', 16)->nullable()->after('edit_action');

            $table->unsignedTinyInteger('edit_confidence')->nullable()->after('edit_risk');

            // In the model's words. A one-line explanation is the difference between an
            // alert somebody reads and one they learn to dismiss.
            $table->string('edit_reasoning', 500)->nullable()->after('edit_confidence');
        });
    }

    public function down(): void
    {
        Schema::table('telegram_signals', function (Blueprint $table) {
            $table->dropColumn(['edit_action', 'edit_risk', 'edit_confidence', 'edit_reasoning']);
        });
    }
};
