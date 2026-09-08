<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What became of every signal, traded or not.
 *
 * Until this table existed the only outcomes on record were the trades that were actually
 * placed - seven in a month on the account this was built on. Every signal the strategy
 * declined, every copied signal the reviewer turned down, and every signal read by hand
 * from the card left no trace of whether it would have worked. So the win rate could not
 * be measured, the confidence score could not be calibrated, and every argument about a
 * filter was an argument about an opinion.
 *
 * One row per signal, opened when the signal is recorded and advanced as bars arrive: the
 * best and worst the price did against the levels the signal named, which level was hit
 * first, how many bars each took, and where the close sat one, five and twenty bars on.
 * The bars are the ones the terminal already pushes; nothing new is fetched.
 *
 * `context` carries the readings the stats group by - confidence band, session, hour,
 * instrument - copied at the time so a later change to how they are computed does not
 * quietly re-bucket history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('signal_outcomes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // The signal this is the outcome of: App\Models\Signal or App\Models\TelegramSignal.
            $table->string('subject_type', 64);
            $table->unsignedBigInteger('subject_id');
            $table->string('source', 16); // ai | copied

            $table->foreignId('broker_account_id')->nullable()->constrained()->nullOnDelete();
            $table->string('symbol', 32);      // as the terminal stores bars: XAUUSDm, not XAUUSD
            $table->string('timeframe', 10);
            $table->string('direction', 8);

            // The levels the signal named, and the R they define.
            $table->decimal('reference_price', 16, 6);
            $table->decimal('stop_price', 16, 6);
            $table->decimal('tp1_price', 16, 6)->nullable();
            $table->decimal('tp2_price', 16, 6)->nullable();
            $table->decimal('tp3_price', 16, 6)->nullable();
            $table->decimal('risk', 16, 6); // |reference - stop| in price

            // The walk.
            $table->timestamp('started_at');               // open time of the first bar counted
            $table->timestamp('last_bar_at')->nullable();  // newest bar folded in
            $table->unsignedInteger('bars_seen')->default(0);
            $table->unsignedInteger('horizon_bars');

            $table->decimal('mfe_r', 10, 4)->nullable(); // best excursion in the trade's favour
            $table->decimal('mae_r', 10, 4)->nullable(); // worst excursion against it
            $table->unsignedInteger('tp1_bars')->nullable();
            $table->unsignedInteger('tp2_bars')->nullable();
            $table->unsignedInteger('tp3_bars')->nullable();
            $table->unsignedInteger('sl_bars')->nullable();
            $table->string('first_hit', 8)->nullable(); // tp1 | sl
            $table->decimal('r_at_1', 10, 4)->nullable();
            $table->decimal('r_at_5', 10, 4)->nullable();
            $table->decimal('r_at_20', 10, 4)->nullable();

            // open | won | lost | expired. Decided by first_hit; expired when the horizon
            // passed with neither level touched.
            $table->string('status', 12)->default('open')->index();
            $table->timestamp('resolved_at')->nullable();

            $table->json('context')->nullable();
            $table->timestamps();

            $table->unique(['subject_type', 'subject_id']);
            $table->index(['user_id', 'status']);
            $table->index(['broker_account_id', 'symbol', 'timeframe', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('signal_outcomes');
    }
};
