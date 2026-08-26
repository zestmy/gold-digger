<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Protect Copied Positions By Default
 *
 * A new account starts with the copier protection configured rather than blank.
 *
 * WHY the default flips:
 * `copier_protect_at_r` being null means `PositionManager::manage()` returns before it
 * looks at anything, so out of the box a copied position had exactly two things minding
 * it: the stop the order carries, and whatever the provider remembers to post. That was
 * the right default while the protection was new and unproven on this account. It is the
 * wrong one to hand somebody who has just registered, because the failure it produces -
 * a winner that gave everything back while nobody was watching - looks like the market's
 * fault rather than a setting nobody knew to turn on.
 *
 * WHY this does not disturb anyone already running:
 * A column default applies to rows inserted after it, and to nothing else. Every existing
 * `bot_settings` row keeps exactly the values it has, including the deliberate nulls on a
 * deployment that chose to leave copied positions alone. `PositionManager`'s own note -
 * "a deployment that predates these settings was not managing copied trades and must not
 * start silently" - still holds, because that deployment's row is not touched here.
 *
 * WHY this is not a licence to trade:
 * `is_active` is still false by default. The kill switch governs whether anything may be
 * opened or modified at all, and `manage()` checks it first. So this means "protected once
 * you turn the bot on", never "acting from the moment you register".
 *
 * WHY the values:
 * They are the ones this account settled on - bank half at 1R, then trail 1R behind the
 * best price, with break-even plus 30 pips standing behind it if the trail is ever
 * cleared. Trailing supersedes break-even in `actionsFor()` rather than running beside it,
 * so `copier_breakeven` and its offset are the fallback rather than a second action.
 *
 * WHY every attribute is restated below:
 * `change()` rewrites the column from the definition it is given. An attribute left out is
 * an attribute dropped, so nullability and precision are repeated verbatim from
 * `add_copier_protection_to_bot_settings` and `add_copier_breakeven_offset`. Omitting
 * `nullable()` here would make four columns NOT NULL as a side effect of setting a
 * default, which is the sort of thing that only surfaces when something later tries to
 * clear one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bot_settings', function (Blueprint $table) {
            $table->decimal('copier_protect_at_r', 5, 2)->nullable()->default(1.00)->change();
            $table->boolean('copier_breakeven')->default(true)->change();
            $table->decimal('copier_breakeven_offset_pips', 8, 2)->nullable()->default(30.00)->change();
            $table->unsignedTinyInteger('copier_profit_lock_pct')->nullable()->default(50)->change();
            $table->decimal('copier_trail_distance_r', 5, 2)->nullable()->default(1.00)->change();
        });
    }

    public function down(): void
    {
        Schema::table('bot_settings', function (Blueprint $table) {
            $table->decimal('copier_protect_at_r', 5, 2)->nullable()->default(null)->change();
            $table->boolean('copier_breakeven')->default(false)->change();
            $table->decimal('copier_breakeven_offset_pips', 8, 2)->nullable()->default(null)->change();
            $table->unsignedTinyInteger('copier_profit_lock_pct')->nullable()->default(null)->change();
            $table->decimal('copier_trail_distance_r', 5, 2)->nullable()->default(null)->change();
        });
    }
};
