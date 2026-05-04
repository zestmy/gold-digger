<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bot Settings Migration
 *
 * Stores per-user trading bot configuration. Each user has one BotSettings record
 * that controls risk management, session filters, and screenshot capture preferences.
 *
 * WHY separate table instead of columns on users?
 * - Cleaner separation of concerns (auth vs trading config)
 * - Easier to reset to defaults without touching user record
 * - Future SaaS: different subscription tiers could have different defaults
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bot_settings', function (Blueprint $table) {
            $table->id();

            // Each user has exactly one bot_settings record
            // cascade delete: if user is deleted, their settings go too
            $table->foreignId('user_id')
                ->constrained()
                ->cascadeOnDelete();

            // Master on/off switch for the trading bot
            $table->boolean('is_active')->default(false);

            // Risk management: percentage of account balance to risk per trade
            // Example: 1.00 means risk 1% of balance per trade
            $table->decimal('risk_percentage', 5, 2)->default(1.00);

            // Daily loss limit as percentage of starting daily balance
            // Bot stops trading if daily losses exceed this threshold
            $table->decimal('max_daily_loss_percentage', 5, 2)->default(3.00);

            // Maximum number of trades that can be open simultaneously
            $table->integer('max_concurrent_trades')->default(3);

            // JSON array of allowed trading sessions
            // Example: ["london", "newyork", "overlap"]
            // Bot only enters trades during these market sessions
            $table->json('allowed_sessions')->nullable();

            // Minimum ATR (Average True Range) required to enter a trade
            // Filters out low-volatility periods where scalping is less effective
            $table->decimal('min_atr_threshold', 10, 2)->nullable();

            // Skip trading during high-impact news events
            $table->boolean('news_filter_enabled')->default(true);

            // Automatically capture chart screenshots for trade review
            $table->boolean('capture_screenshots')->default(true);

            $table->timestamps();

            // Each user can only have one bot_settings record
            $table->unique('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bot_settings');
    }
};
