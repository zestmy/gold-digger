<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which kind of setup a reading was about.
 *
 * The analyst already said what it thought in prose. Prose cannot be filtered, grouped or
 * counted, so "do the breakout readings work better than the pullbacks" was a question the
 * history could not answer despite holding every reading needed to answer it.
 *
 * Nullable, and null is a real answer rather than a gap. `SetupClassifier` offers only the
 * patterns whose own definition the conditions actually meet, and most of the time that
 * list is empty - a market between levels with no trend, no break and no rejection is not a
 * weak example of something, it is nothing in particular. Storing that as null is what
 * keeps the eventual comparison honest: a column that always held a type would be measuring
 * a vocabulary rather than the market.
 *
 * No enum constraint at the database level on purpose. The seven keys are a judgement about
 * how to carve up price action, not a fact about it, and the next one is likely to be
 * added rather than the list being final. A check constraint would make adding one a
 * migration against a live table for no protection the schema class does not already give.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chart_analyses', function (Blueprint $table) {
            $table->string('setup_type', 32)->nullable()->after('plan');

            // "Which kinds has this account been shown, and how did they go" - the question
            // the column exists for, asked per tenant.
            $table->index(['user_id', 'setup_type']);
        });
    }

    public function down(): void
    {
        Schema::table('chart_analyses', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'setup_type']);
            $table->dropColumn('setup_type');
        });
    }
};
