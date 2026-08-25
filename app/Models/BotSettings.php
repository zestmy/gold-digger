<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Bot Settings Model
 *
 * Stores per-user trading bot configuration. Each user has exactly one
 * BotSettings record, created automatically when they register.
 *
 * This model controls:
 * - Master bot on/off switch (is_active)
 * - Risk management parameters (risk_percentage, max_daily_loss_percentage)
 * - Trade filters (allowed_sessions, news_filter_enabled)
 * - Screenshot capture preferences
 */
class BotSettings extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'is_active',
        'risk_percentage',
        'max_daily_loss_percentage',
        'max_concurrent_trades',
        'allowed_sessions',
        'min_atr_threshold',
        'news_filter_enabled',
        'news_blackout_before_minutes',
        'news_blackout_after_minutes',
        'ai_trading_enabled',
        'ai_capital_cap',
        'ai_risk_percentage',
        'ai_max_concurrent_trades',
        'capture_screenshots',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            // Booleans
            'is_active' => 'boolean',
            'news_filter_enabled' => 'boolean',
            'capture_screenshots' => 'boolean',

            // Minutes either side of a high-impact release. Cast so NewsBlackout compares
            // integers rather than the strings a raw column read would give it.
            'news_blackout_before_minutes' => 'integer',
            'news_blackout_after_minutes' => 'integer',

            // The AI fund. `ai_capital_cap` stays nullable rather than cast to a float:
            // absent means nobody has decided how much this may lose, which is a different
            // statement from zero and must not collapse into one.
            'ai_trading_enabled' => 'boolean',
            'ai_max_concurrent_trades' => 'integer',

            // Decimals - Laravel casts these to strings for precision
            'risk_percentage' => 'decimal:2',
            'max_daily_loss_percentage' => 'decimal:2',
            'min_atr_threshold' => 'decimal:2',

            // JSON array for allowed trading sessions
            // Access as: $settings->allowed_sessions returns ['london', 'newyork']
            'allowed_sessions' => 'array',
        ];
    }

    // =========================================================================
    // RELATIONSHIPS
    // =========================================================================

    /**
     * The user these settings belong to.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
