<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Trading mode: passive, moderate, aggressive - or custom.
 *
 * The risk page holds eighteen numbers that together are a stance nobody can read as
 * one. A mode is the stance said in a word, and choosing it writes the word's values
 * into the same columns the form edits. Nothing reads the mode to decide a trade; see
 * App\Support\TradingMode for why that is what makes it honest.
 *
 * Existing rows are `custom`: their values were set by hand, and calling them moderate
 * would be a claim the numbers may not support. A new account starts moderate, which is
 * the platform's defaults under another name.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bot_settings', function (Blueprint $table) {
            $table->string('trading_mode', 16)->default('custom')->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('bot_settings', function (Blueprint $table) {
            $table->dropColumn('trading_mode');
        });
    }
};
