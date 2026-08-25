<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Commitments of Traders, as published weekly by the CFTC.
 *
 * ## What this is, and what it is not
 *
 * A count of how futures positions were distributed among categories of trader as of a
 * Tuesday, published the following Friday. It is a genuine measurement and it is stale by
 * design: by the time it is readable the market has had three days to move.
 *
 * That makes it a poor input to an entry decision on a five-minute chart, and it is
 * deliberately not wired into the confluence score for that reason. Grading an M5 entry
 * against Tuesday's positioning would be adding a number that cannot move at the speed the
 * decision is made, which is a way of looking rigorous rather than being it.
 *
 * What it is good for is context - whether speculative positioning in gold is historically
 * crowded, and in which direction - and that is what it is used as: a reading shown to a
 * person, and a line in the brief an AI writes its case against.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cot_reports', function (Blueprint $table) {
            $table->id();

            // The CFTC's own market name, kept verbatim so a mapping error is visible
            // rather than silently attaching gold's positioning to the pound.
            $table->string('market', 160);

            // The Tuesday the positions were counted, not the Friday they were published.
            $table->date('report_date');

            $table->integer('noncommercial_long');
            $table->integer('noncommercial_short');
            $table->integer('commercial_long')->nullable();
            $table->integer('commercial_short')->nullable();
            $table->integer('open_interest')->nullable();

            $table->timestamp('fetched_at')->useCurrent();
            $table->timestamps();

            // One row per market per week. Re-fetching corrects rather than duplicates,
            // which matters because the CFTC revises.
            $table->unique(['market', 'report_date']);
            $table->index('report_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cot_reports');
    }
};
