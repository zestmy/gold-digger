<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bring `news_blackout_before_minutes` / `news_blackout_after_minutes` into the migrations.
 *
 * ## These columns already existed, and that is the problem
 *
 * Both are present in the local and production databases and in no migration. They were
 * added out of band at some point, so the schema a fresh `php artisan migrate` produces
 * has never matched the schema anything actually runs against.
 *
 * That went unnoticed because nothing read them: `news_filter_enabled` was displayed and
 * unenforced, and the two windows it needs were never consulted. The moment the filter
 * became real the drift surfaced immediately - on a fresh database the columns are absent,
 * `(int) null` is 0, and a zero-width window blacks out nothing. A risk control that
 * silently does nothing on every newly provisioned environment, including CI, is exactly
 * the failure the filter was added to prevent.
 *
 * ## Guarded, because the columns may or may not be there
 *
 * Production has them; a clean checkout does not. `hasColumn` is what lets one migration
 * be correct in both cases rather than failing on the machine that matters most.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bot_settings', function (Blueprint $table) {
            // Fifteen minutes either side is the conventional window for a high-impact
            // release, and matches the values already sitting in the drifted databases -
            // so this migration changes no existing behaviour, it only makes the schema
            // reproducible.
            if (! Schema::hasColumn('bot_settings', 'news_blackout_before_minutes')) {
                $table->unsignedSmallInteger('news_blackout_before_minutes')->default(15)->after('news_filter_enabled');
            }

            if (! Schema::hasColumn('bot_settings', 'news_blackout_after_minutes')) {
                $table->unsignedSmallInteger('news_blackout_after_minutes')->default(15)->after('news_blackout_before_minutes');
            }
        });
    }

    public function down(): void
    {
        Schema::table('bot_settings', function (Blueprint $table) {
            $table->dropColumn(['news_blackout_before_minutes', 'news_blackout_after_minutes']);
        });
    }
};
