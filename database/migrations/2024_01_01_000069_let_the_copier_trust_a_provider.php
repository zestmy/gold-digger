<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How a copied signal is approved.
 *
 * `model` is what the copier has always done: every signal that clears the mechanical
 * gates is put to a model that declines unless there is a positive case - reward at the
 * exit, the direction against the higher-timeframe trend the account's own strategy reads,
 * the stop against ATR. That is a second opinion on the provider's judgement, and for an
 * account that subscribed to a provider *for* their judgement it is the wrong default: it
 * declined most of what a good provider posted, for reasons the provider never claimed
 * to be trading on.
 *
 * `gates` trusts the provider. A signal is traded when it is still valid - it parsed, the
 * kill switch is on, the fund has room, the session and news filters allow it, it is not
 * too old, and price has not run past the entry or through the stop - and nothing judges
 * whether it is a good trade. That is the provider's job, and the channel's own record
 * on the Providers page is where it is judged.
 *
 * The default stays `model`. Trusting a stranger's trades is a choice to make, not one
 * to inherit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bot_settings', function (Blueprint $table) {
            $table->string('copier_review', 16)->default('model')->after('copier_levels');
        });
    }

    public function down(): void
    {
        Schema::table('bot_settings', function (Blueprint $table) {
            $table->dropColumn('copier_review');
        });
    }
};
