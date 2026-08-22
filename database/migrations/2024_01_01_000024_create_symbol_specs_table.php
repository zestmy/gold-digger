<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Symbol Specifications
 *
 * What the terminal knows about each instrument, one row per symbol per account.
 *
 * ## Why this stops being heartbeat columns
 *
 * `bot_heartbeats` carries `resolved_symbol`, `pip_size`, `pip_value_per_lot`, `volume_min` and
 * `volume_step` - a single symbol's worth, because there was only ever one. Everything in the
 * strategy layer reads them from there, so trading a second instrument was not a configuration
 * change but a schema change.
 *
 * The heartbeat is the wrong home for them anyway. Balance, equity, whether Algo Trading is on
 * and whether the broker is connected are properties of the *account*; pip size and contract
 * size are properties of an *instrument*. Storing the second on a row that describes the first
 * is what limited the system to one symbol.
 *
 * ## Two names, because there are two names
 *
 * `base_symbol` is what a strategy asks for - XAUUSD. `symbol` is what the broker actually
 * publishes it as - XAUUSDm, XAUUSD.a, GOLD. The terminal resolves one to the other at runtime
 * by scanning its own symbol list, and this is where that resolution is recorded so the
 * dashboard can follow it.
 *
 * Keeping both is what lets a strategy stay portable. `strategies.symbol` names the instrument
 * in the abstract; moving the same strategy to a broker with different suffixes needs no edit.
 *
 * ## Written by the candle push
 *
 * Rather than by the heartbeat, because a spec describes the bars it arrives with. Every symbol
 * that has price history therefore has a specification, and the two cannot drift apart or
 * arrive in the wrong order.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('symbol_specs', function (Blueprint $table) {
            $table->id();

            $table->foreignId('broker_account_id')
                ->constrained()
                ->cascadeOnDelete();

            // What a strategy asks for.
            $table->string('base_symbol', 32);

            // What this broker publishes it as.
            $table->string('symbol', 32);

            // Price movement of one pip, in quote units. Gold: 0.10 against a point of 0.01.
            $table->decimal('pip_size', 12, 5)->nullable();

            $table->unsignedTinyInteger('digits')->nullable();

            // Account-currency value of a one-pip move on one lot. The whole of position
            // sizing, and unknowable outside the terminal.
            $table->decimal('pip_value_per_lot', 12, 5)->nullable();

            // Smallest tradeable volume, and the granularity above it. Together they decide
            // whether a position can be divided into a take-profit ladder at all.
            $table->decimal('volume_min', 12, 4)->nullable();
            $table->decimal('volume_step', 12, 4)->nullable();

            $table->timestamp('last_seen_at')->useCurrent();

            $table->timestamps();

            // One specification per instrument per account. Keyed on the *base* name, because
            // that is what a strategy names and what a lookup starts from.
            $table->unique(['broker_account_id', 'base_symbol'], 'symbol_specs_account_base_unique');

            // The reverse lookup: given bars stored under a resolved name, whose spec is this.
            $table->index(['broker_account_id', 'symbol'], 'symbol_specs_account_symbol_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('symbol_specs');
    }
};
