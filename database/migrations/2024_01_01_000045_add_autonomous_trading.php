<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Trading on its own opinion rather than on somebody else's.
 *
 * Off by default and separate from `ai_trading_enabled`, which governs whether the fund
 * may be spent at all. They are different permissions: copying a vetted provider and
 * forming an independent view are not the same act, and somebody who wants the first has
 * not thereby asked for the second.
 *
 * The instruments are listed rather than inferred from what has candles. A terminal
 * pushing five symbols has not thereby volunteered all five for autonomous trading.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bot_settings', function (Blueprint $table) {
            $table->boolean('ai_autonomous')->default(false)->after('ai_max_trades_per_day');
            $table->json('ai_autonomous_symbols')->nullable()->after('ai_autonomous');
        });
    }

    public function down(): void
    {
        Schema::table('bot_settings', function (Blueprint $table) {
            $table->dropColumn(['ai_autonomous', 'ai_autonomous_symbols']);
        });
    }
};
