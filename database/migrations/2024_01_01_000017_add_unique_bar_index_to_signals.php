<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One Signal Per Strategy Per Bar
 *
 * SignalGenerator checks for an existing signal on the bar before recording one, but a
 * check-then-insert is not a guarantee. Two candle pushes can overlap: `WebRequest` is
 * synchronous with a short timeout, so an EA that times out client-side while the server
 * is still working retries on its next timer while the first request is in flight. Both
 * would find no signal and both would record one.
 *
 * That is the expensive failure. Each signal gets its own command idempotency key
 * (`signal:{id}`), so two signals for one bar means two keys, two commands, and two
 * positions opened on a setup that justified one.
 *
 * With this index the second insert fails instead, and `createOrFirst` returns the row the
 * winner wrote. The application-level check stays: it is what avoids the write on the
 * ordinary path, where the same closed bar is re-pushed on every new bar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('signals', function (Blueprint $table) {
            $table->unique(['strategy_id', 'generated_at'], 'signals_strategy_bar_unique');
        });
    }

    public function down(): void
    {
        Schema::table('signals', function (Blueprint $table) {
            $table->dropUnique('signals_strategy_bar_unique');
        });
    }
};
