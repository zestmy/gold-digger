<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add Copier Breakeven Offset
 *
 * How far past the entry a copied position's break-even stop actually goes.
 *
 * WHY "break even" at the entry is not break-even:
 * A position that closes at the price it opened at has still paid to be there - the spread
 * crossed on entry, commission both sides, any slippage. Moving the stop to the entry
 * exactly and calling it break-even books that cost as a loss on every trade the
 * protection saves. On gold with a two-point spread against a five-point stop that is a
 * substantial share of 1R handed back on each scratch, and it happens on exactly the
 * trades this feature exists to rescue.
 *
 * The strategy path has had `strategies.breakeven_offset_pips` for this reason since
 * `TradeManager` was written. The copier had no equivalent, so `copier_breakeven` moved
 * the stop to the entry and stopped there.
 *
 * WHY pips, in a class that measures everything else in R:
 * R is the right unit for anything about the *trade* - a copied stop is a stranger's
 * choice, so "bank half at 1R" is the only phrasing correct across providers. This is not
 * about the trade. It is about what the broker charges to hold the instrument, which is a
 * fact about the instrument and the account and is the same size whether the provider
 * risked five points or forty. Expressing it in R would make the padding shrink on tight
 * stops, which is precisely where the cost matters most.
 *
 * WHY nullable rather than defaulted:
 * Null and zero both mean "the entry exactly", which is what every deployment does today.
 * Nullable keeps "never configured" distinguishable from "configured to zero" in the one
 * place that reads it, and neither changes behaviour.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bot_settings', function (Blueprint $table) {
            $table->decimal('copier_breakeven_offset_pips', 8, 2)
                ->nullable()
                ->after('copier_breakeven');
        });
    }

    public function down(): void
    {
        Schema::table('bot_settings', function (Blueprint $table) {
            $table->dropColumn('copier_breakeven_offset_pips');
        });
    }
};
