<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Settings that belong to one channel rather than to the account.
 *
 * ## Why this is the feature that makes the analytics useful
 *
 * Per-channel P&L already exists, and without this it is a dead end: the page can say that
 * one provider outperforms another and then offers exactly one lever, on or off. A channel
 * that is mediocre but not worthless has no home in that world - it is either taking full
 * risk or nothing.
 *
 * With overrides, the measurement has somewhere to go. Half risk on the middling one, the
 * provider's own levels on the one whose stops are good, this account's levels on the one
 * whose stops are not.
 *
 * ## Every column is nullable, and null means inherit
 *
 * Not "copy the global value at the time the channel was created". A channel that froze a
 * snapshot of the settings would keep trading last month's risk percentage after the
 * account's was lowered, and nothing on screen would say so. Null resolves against the
 * account every time it is read, so lowering risk globally lowers it everywhere that has
 * not deliberately said otherwise.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('telegram_channels', function (Blueprint $table) {
            // Risk as a percentage of the remaining fund, overriding the account's.
            $table->decimal('risk_percentage', 5, 2)->nullable()->after('is_enabled');

            // 'provider' or 'strategy'. Which levels a copied trade actually carries -
            // worth deciding per provider, because it is a judgement about their stops.
            $table->string('copier_levels', 16)->nullable()->after('risk_percentage');

            $table->unsignedTinyInteger('max_trades_per_day')->nullable()->after('copier_levels');

            // A channel that posts loosely can be held to a higher bar without raising it
            // for the one that does not.
            $table->decimal('min_confluence', 4, 1)->nullable()->after('max_trades_per_day');

            // Take only these instruments, or take everything except these. A provider who
            // is good at gold and careless with indices is a common shape.
            $table->json('symbols_allow')->nullable()->after('min_confluence');
            $table->json('symbols_deny')->nullable()->after('symbols_allow');

            // Reading a screenshot costs a model call and can be confidently wrong. Worth
            // switching off for a channel that posts charts as commentary.
            $table->boolean('read_images')->nullable()->after('symbols_deny');
        });
    }

    public function down(): void
    {
        Schema::table('telegram_channels', function (Blueprint $table) {
            $table->dropColumn([
                'risk_percentage', 'copier_levels', 'max_trades_per_day',
                'min_confluence', 'symbols_allow', 'symbols_deny', 'read_images',
            ]);
        });
    }
};
