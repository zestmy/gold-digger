<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every chart reading the analyst has produced, kept.
 *
 * Until now a reading lived in the cache for fifteen minutes and then stopped existing. It
 * was shown, it was acted on or it was not, and nothing anywhere recorded what it had said.
 * That is defensible for a tool one person uses and indefensible for a product, for three
 * separate reasons:
 *
 * 1. **It cannot be checked.** "Was the analyst any good" is a question about a run of
 *    readings against what price then did, and it is unanswerable if the readings are gone.
 *    This is the same argument the `signals` table already makes for the mechanical
 *    strategy - including the refusals, because a filter that skipped every winner is a
 *    fact only visible in what it declined.
 * 2. **It cannot be shown.** A customer who read something convincing on Tuesday has no way
 *    back to it, and "what did it say last time" is the first thing anybody asks.
 * 3. **It cannot be disputed.** A system that gives trading opinions and keeps no record of
 *    them is one where every disagreement is a matter of recollection.
 *
 * ## Waiting is recorded too, and that is the point
 *
 * A reading whose plan is `wait` has no entry, stop or target, and those columns stay null
 * rather than being filled with something plausible. Storing the refusals is what makes the
 * history worth having: a analyst that says "no setup" for a week during which price went
 * nowhere was right, and there is no way to know that from the trades it did not cause.
 *
 * ## Keyed by the bar, not by the request
 *
 * The unique index is (owner, symbol, timeframe, bar). A reading is *of* a bar - asking
 * twice within one bar is the same question, which is already why the cache key is built
 * this way. Without the index a customer leaning on refresh would produce a history of
 * duplicates that looked like a change of mind.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chart_analyses', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Which series it was read from. Nullable for the same reason it is elsewhere:
            // a reading taken before any account was bound still happened.
            $table->foreignId('broker_account_id')->nullable()->constrained()->nullOnDelete();

            $table->string('symbol', 32);
            $table->string('timeframe', 10);

            // The bar this reading is *of*. The identity of the row, with the three above.
            $table->timestamp('bar_open_time');

            $table->enum('bias', ['bullish', 'bearish', 'neutral']);

            // 'wait' is a first-class outcome and the most common one. It is not a failure
            // to produce a plan; it is a plan.
            $table->enum('plan', ['buy', 'sell', 'wait']);

            $table->string('headline', 500);
            $table->text('structure');
            $table->text('reasoning');
            $table->text('invalidation');

            // Null when waiting. Never invented - see the note above.
            $table->decimal('entry_price', 16, 6)->nullable();
            $table->decimal('stop_price', 16, 6)->nullable();
            $table->decimal('target_price', 16, 6)->nullable();
            $table->decimal('reward_ratio', 8, 2)->nullable();

            // The measured evidence, stored beside the opinion it produced. Without this a
            // historical reading cannot be re-read - the levels it named would have to be
            // recomputed from bars that have since scrolled out of the window, which is to
            // say they could not be.
            $table->json('levels')->nullable();
            $table->json('timeframes')->nullable();
            $table->json('events')->nullable();

            // Which model actually served it, and which prompt produced it. When a reading
            // looks unlike the others, the first question is whether either had changed.
            $table->string('model', 64)->nullable();
            $table->unsignedSmallInteger('prompt_version')->default(1);

            $table->timestamps();

            // Asking twice within one bar is the same question.
            $table->unique(['user_id', 'symbol', 'timeframe', 'bar_open_time'], 'chart_analyses_bar_unique');

            // The history page: this tenant's readings, newest first.
            $table->index(['user_id', 'created_at']);

            // "What has it said about gold" - the other way anybody reads this table.
            $table->index(['user_id', 'symbol', 'timeframe']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chart_analyses');
    }
};
