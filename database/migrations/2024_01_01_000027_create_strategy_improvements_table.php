<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Runs of the strategy improver.
 *
 * A walk-forward over twenty thousand bars takes minutes, not milliseconds, so the
 * dashboard cannot hold a request open for one. The run is queued and its result lands
 * here, which also means a run survives a page refresh, a closed laptop, and a deploy.
 *
 * Keeping the results rather than showing them once is the more useful half. "We tried
 * loosening ADX in August and it made no difference out of sample" is exactly the thing
 * that gets forgotten and re-litigated three months later, and `BACKTESTING.md` exists
 * because these settings used to be argued about rather than measured.
 *
 * Nothing here is ever applied automatically. The row records what was proposed and what
 * it measured; changing a strategy stays a deliberate act.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('strategy_improvements', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('strategy_id')->constrained()->cascadeOnDelete();

            // queued / running / done / failed. A run that dies mid-way must be
            // distinguishable from one that finished and found nothing.
            $table->string('status', 16)->default('queued')->index();

            // What was asked for, so a result can be reproduced and two runs compared.
            $table->json('options')->nullable();

            $table->json('baseline')->nullable();
            $table->json('proposed')->nullable();
            $table->json('proposals')->nullable();

            // Carried alongside the numbers rather than recomputed for display, so a view
            // cannot render the table and quietly drop the warning that qualifies it.
            $table->boolean('thin')->default(true);
            $table->text('verdict')->nullable();

            $table->string('model')->nullable();
            $table->text('error')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('strategy_improvements');
    }
};
