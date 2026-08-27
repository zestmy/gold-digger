<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The two floors an entry has to clear, and a third that never existed.
 *
 * ## Confluence, which was a constant
 *
 * `SignalQuality::MIN_CONFLUENCE` and `MIN_DIRECTIONAL` are the SOP's "three confluences"
 * rule and the directional half of it. Both were compile-time constants, so the only way
 * to run a stricter book - or a looser one on a demo account - was to edit and deploy.
 *
 * Per-channel overrides already existed (`telegram_channels.min_confluence`), which is the
 * odd shape this corrects: a provider could be held to a different bar than the account
 * itself, but the account had no bar to state. These columns are the account's own, and
 * they are what a channel with no override now falls back to.
 *
 * ## Reward against risk, which was measured and never enforced
 *
 * `MarketScanner`, `SignalPlan` and `ChartAnalyst` all divide out a reward-to-risk ratio.
 * They display it, they store it, and the reviewer puts it in front of the model. Nothing
 * anywhere refused a trade because it was poor - so a copied signal offering to risk three
 * to make one passed every gate this system has, because none of them looked.
 *
 * ## All three are nullable, and the reward floor is off by default
 *
 * Null means "use the platform default", which for confluence is the constant that has
 * always applied - so this migration changes nothing about what any existing deployment
 * trades. For the reward floor the platform default is *also* none, and that is the
 * deliberate part.
 *
 * A floor turned on by default would start refusing trades that currently execute, on a
 * live copier, on the strength of a migration nobody read. Whether 1.5R is a sensible bar
 * depends on a win rate this project has not measured - plenty of profitable books run
 * below 1R - so the operator opts in rather than opting out.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bot_settings', function (Blueprint $table) {
            // Reward to risk, measured to the take-profit the order actually carries -
            // not to an intermediate rung the position never exits at. 1.50 means "make
            // at least one and a half times what is being risked".
            $table->decimal('min_reward_ratio', 5, 2)->nullable()->after('min_atr_threshold');

            // Weighted factors that must agree. Half-steps are real: several factors are
            // weighted 0.5 because they are half an observation.
            $table->decimal('min_confluence', 4, 1)->nullable()->after('min_reward_ratio');

            // How much of that agreement has to be about direction rather than about
            // permission. Without it, an open session with no news due and ordinary
            // volatility reaches the confluence floor in a market where nothing at all
            // agrees which way to trade.
            $table->decimal('min_directional', 4, 1)->nullable()->after('min_confluence');
        });
    }

    public function down(): void
    {
        Schema::table('bot_settings', function (Blueprint $table) {
            $table->dropColumn(['min_reward_ratio', 'min_confluence', 'min_directional']);
        });
    }
};
