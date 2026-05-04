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
