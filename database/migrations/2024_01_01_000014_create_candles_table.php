<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Candles Migration
 *
 * The price history the strategy layer reads. Until now nothing in this repo stored a
 * price series at all, so no indicator could be computed and nothing could ever enqueue
 * an `open` command - which is exactly why signal generation was the missing piece.
 *
 * WHY the executor's own feed, and not a market-data API?
 * Indicators decide *where the stop goes* (sl_atr_multiplier * ATR). If ATR is computed
 * from one vendor's gold series and the order is filled against the broker's, the stop
 * is sized from prices the broker never quoted. That is the same failure the pip trap
 * causes, arriving by a different route. The terminal already has the authoritative
 * series via CopyRates, so it pushes it here.
 *
 * WHY source-agnostic anyway?
 * `source` records who wrote the row. Nothing in the indicator or signal layer reads it,
 * so adding a vendor backfill later (for backtesting over history the terminal cannot
 * reach) means writing rows with a different `source` and changing nothing downstream.
 *
 * WHY only closed bars?
 * A forming bar's high, low and close all still move. An EMA cross computed on one would
 * appear and disappear within the same bar, firing entries that the completed bar never
 * justified. `CandleController` rejects anything the executor has not marked closed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('candles', function (Blueprint $table) {
            $table->id();

            // Whose data this is. Series are per-account because two brokers quote gold
            // differently enough (spread, feed, session breaks) that mixing them would
            // produce indicator values matching neither.
            $table->foreignId('user_id')
                ->constrained()
                ->cascadeOnDelete();

            // NOT NULL, unlike most broker_account_id columns here, because the unique
            // index below is the only thing preventing a duplicated bar - and NULL never
            // collides in a unique index. A nullable column would silently allow the same
            // bar twice for any token not bound to an account, and a doubled bar corrupts
            // every indicator computed over it. CandleController rejects unbound tokens
            // for the same reason FillController does.
            $table->foreignId('broker_account_id')
                ->constrained()
                ->cascadeOnDelete();

            // The broker's resolved name (XAUUSDm, XAUUSD.a, GOLD), never the generic
            // XAUUSD. The heartbeat reports which one the terminal actually resolved.
            $table->string('symbol', 32);

            // M1, M5, M15, H1, ... as MT5 names them.
            $table->string('timeframe', 10);

            // Bar open time in UTC, as reported by the terminal. The terminal's server
            // time is the only clock the bars are indexed by; re-deriving it from
            // received-at would drift by the poll interval and break bar alignment.
            $table->timestamp('open_time');

            $table->decimal('open', 12, 5);
            $table->decimal('high', 12, 5);
            $table->decimal('low', 12, 5);
            $table->decimal('close', 12, 5);

            // Tick volume, not real volume: MT5 reports real volume only where the
            // broker supplies it, and for retail gold it is almost always zero.
            $table->unsignedBigInteger('tick_volume')->default(0);

            // Spread at bar close, in points. Kept because "was the spread wide when we
            // entered" is unanswerable after the fact, and it is a standing suspect
            // whenever a fill looks worse than the signal implied.
            $table->decimal('spread_points', 8, 2)->nullable();

            // Who wrote the row: "mql5_ea" today.
            $table->string('source', 50)->default('mql5_ea');

            $table->timestamps();

            // Re-sending a bar must overwrite, never duplicate: the EA re-pushes a small
            // trailing window on every poll so a missed push self-heals, and a duplicated
            // bar would corrupt every indicator reading over it.
            $table->unique(['broker_account_id', 'symbol', 'timeframe', 'open_time'], 'candles_series_bar_unique');

            // The only read the indicator layer makes: newest N bars of one series.
            $table->index(['symbol', 'timeframe', 'open_time'], 'candles_series_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('candles');
    }
};
