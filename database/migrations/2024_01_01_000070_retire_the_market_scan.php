<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The market scan is gone, and its readings with it.
 *
 * `/signals/scan` ranked every instrument the account had bars for on measured confluence
 * and asked a model which of the shortlist to prefer; opening a row produced a chart
 * reading stored in `chart_analyses`. In practice the account had bars for one instrument -
 * the EA pushes the strategy's own symbol - so the scan ranked a list of one, and the
 * ranking it offered measured how much evidence agreed, which the outcome data has since
 * shown is not the same thing as an edge. The product decision was to remove it rather
 * than feed it.
 *
 * The readings were kept "so that whether the analyst was any good stays answerable". No
 * one asked, and the table is dropped with the feature. Nothing else referenced it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('chart_analyses');
    }

    public function down(): void
    {
        // Recreated in the shape the last migration left it, so a rollback restores the
        // schema even though the rows are gone.
        Schema::create('chart_analyses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('broker_account_id')->nullable()->constrained()->nullOnDelete();
            $table->string('symbol', 32);
            $table->string('timeframe', 10);
            $table->timestamp('bar_open_time');
            $table->enum('bias', ['bullish', 'bearish', 'neutral']);
            $table->enum('plan', ['buy', 'sell', 'wait']);
            $table->string('setup_type', 32)->nullable();
            $table->string('headline', 500);
            $table->text('structure');
            $table->text('reasoning');
            $table->text('invalidation');
            $table->decimal('entry_price', 16, 6)->nullable();
            $table->decimal('stop_price', 16, 6)->nullable();
            $table->decimal('target_price', 16, 6)->nullable();
            $table->decimal('reward_ratio', 8, 2)->nullable();
            $table->string('model')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'symbol', 'timeframe']);
            $table->index(['user_id', 'setup_type']);
        });
    }
};
