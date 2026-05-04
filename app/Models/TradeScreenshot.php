<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * Trade Screenshot Model
 *
 * Stores chart screenshots captured at key trade events.
 * Screenshots help with post-trade review and pattern recognition.
 *
 * Files are stored in: storage/app/public/screenshots/
 * Accessible via: /storage/screenshots/...
 */
class TradeScreenshot extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'trade_id',
        'screenshot_type',
        'file_path',
        'file_size_kb',
        'timeframe',
        'price_at_capture',
        'notes',
        'captured_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            // Price with 5 decimal places for gold
            'price_at_capture' => 'decimal:5',

            // Timestamp
            'captured_at' => 'datetime',
        ];
    }

    /**
     * The accessors to append to the model's array form.
     *
     * @var array<int, string>
     */
    protected $appends = ['url'];

    // =========================================================================
    // ACCESSORS
    // =========================================================================

    /**
     * Get the public URL for this screenshot.
     *
     * Usage: $screenshot->url returns full URL to the image
     * Example: http://localhost/storage/screenshots/2024/01/trade_123_entry.png
     */
    protected function url(): Attribute
    {
        return Attribute::make(
            get: fn () => Storage::url($this->file_path),
        );
    }

    // =========================================================================
    // RELATIONSHIPS
    // =========================================================================

    /**
     * The trade this screenshot belongs to.
     */
    public function trade(): BelongsTo
    {
        return $this->belongsTo(Trade::class);
    }
}
