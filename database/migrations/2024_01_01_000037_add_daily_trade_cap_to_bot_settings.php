<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A ceiling on how many positions the copier may open in a day.
 *
 * The fund cap bounds how much can be lost in total; this bounds how fast. They are
 * different failures: a provider having a bad day can exhaust a month's budget between
 * breakfast and lunch while every individual trade sizes correctly, and nothing in the
 * cap alone prevents that.
 *
 * Two by default, following the SOP this was drawn from. Counted on positions opened
 * rather than signals approved - the limit exists to bound exposure, and an approval that
 * never filled cost nothing.
 *
 * Scoped to the AI/copier origin. The strategy path has its own risk controls and is not
 * governed by a document about reading somebody else's signals.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bot_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('bot_settings', 'ai_max_trades_per_day')) {
                // Nullable means no ceiling, which is what a deployment that predates this
                // was already doing. Changing behaviour silently on upgrade would be worse
                // than the setting being off.
                $table->unsignedTinyInteger('ai_max_trades_per_day')->nullable()
                    ->after('ai_max_concurrent_trades');
            }
        });
    }

    public function down(): void
    {
        Schema::table('bot_settings', function (Blueprint $table) {
            $table->dropColumn('ai_max_trades_per_day');
        });
    }
};
