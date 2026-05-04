<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Bot Log Model
 *
 * Centralized logging for both Laravel dashboard and Python trading bot.
 * Designed for dashboard display and correlation with trades/signals.
 *
 * Log levels follow PSR-3:
 * - debug: Detailed info (high volume)
 * - info: General events (trade opened, signal generated)
 * - warning: Potential issues (high spread, approaching loss limit)
 * - error: Non-fatal errors (order rejected, retry needed)
 * - critical: Serious errors (MT5 connection lost)
 */
class BotLog extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'level',
        'source',
        'message',
        'context',
        'related_trade_id',
        'related_signal_id',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            // Context JSON - additional structured data
            // Example: {"spread": 0.8, "max_allowed": 0.5}
            'context' => 'array',
        ];
    }

    // =========================================================================
    // RELATIONSHIPS
    // =========================================================================

    /**
     * The trade this log entry relates to (if any).
     */
    public function trade(): BelongsTo
    {
        return $this->belongsTo(Trade::class, 'related_trade_id');
    }

    /**
     * The signal this log entry relates to (if any).
     */
    public function signal(): BelongsTo
    {
        return $this->belongsTo(Signal::class, 'related_signal_id');
    }

    // =========================================================================
    // SCOPES
    // =========================================================================

    /**
     * Filter logs by level.
     */
    public function scopeLevel($query, string $level)
    {
        return $query->where('level', $level);
    }

    /**
     * Filter logs by source.
     */
    public function scopeSource($query, string $source)
    {
        return $query->where('source', $source);
    }

    /**
     * Get only error and critical logs.
     */
    public function scopeErrors($query)
    {
        return $query->whereIn('level', ['error', 'critical']);
    }
}
