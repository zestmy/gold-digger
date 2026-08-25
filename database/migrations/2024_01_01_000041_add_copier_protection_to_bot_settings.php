<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Managing a copied position after it is open.
 *
 * ## The gap this closes
 *
 * TradeManager has trailed stops and moved them to break-even since it was written, and it
 * selects `origin = 'bot'` - so none of it ever applied to a copied trade. A copied
 * position had exactly two things looking after it: the stop the order carries, and
 * whatever the provider remembers to post.
 *
 * That is a poor arrangement for an autonomous copier. Providers go quiet, sleep, and post
 * "secure half" twenty minutes after the move that warranted it.
 *
 * ## Why the triggers are in R rather than in pips
 *
 * The strategy path measures in pips because its stop distance is its own, derived from
 * ATR and roughly stable. A copied trade's stop is whatever a stranger chose: five points
 * on one signal and forty on the next. "Move to break-even at 20 pips" is a third of the
 * way on one and four times the stop on the other.
 *
 * In R it means the same thing every time - at one times what this trade risked - which is
 * the only unit under which a single setting can be correct across providers.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bot_settings', function (Blueprint $table) {
            // Null everywhere means the feature is off, which is what a deployment
            // predating this was already doing.
            $table->decimal('copier_protect_at_r', 5, 2)->nullable()->after('ai_max_trades_per_day');

            // Move the stop to entry when the trigger is reached.
            $table->boolean('copier_breakeven')->default(false)->after('copier_protect_at_r');

            // And take this share of what is left off the table at the same moment.
            $table->unsignedTinyInteger('copier_profit_lock_pct')->nullable()->after('copier_breakeven');

            // Trailing, once the trigger is passed: the stop follows the best price seen at
            // this distance, in R, and never retreats.
            $table->decimal('copier_trail_distance_r', 5, 2)->nullable()->after('copier_profit_lock_pct');
        });
    }

    public function down(): void
    {
        Schema::table('bot_settings', function (Blueprint $table) {
            $table->dropColumn([
                'copier_protect_at_r', 'copier_breakeven',
                'copier_profit_lock_pct', 'copier_trail_distance_r',
            ]);
        });
    }
};
