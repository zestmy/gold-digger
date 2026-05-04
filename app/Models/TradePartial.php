<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Trade Partial Model
 *
 * Records each partial close of a trade. Our strategy uses progressive
 * profit-taking where we close portions of the position at each TP level:
 * - TP1: Close 50% of position
 * - TP2: Close 30% of remaining
 * - TP3: Close final 20%
 *
 * Each partial has its own execution price and P&L calculation.
 */
class TradePartial extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'trade_id',
        'mt5_deal_ticket',
        'closed_lot_size',
        'close_price',
        'close_reason',
        'pips_profit',
        'gross_money_profit',
        'commission_money',
        'swap_money',
        'net_money_profit',
        'closed_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            // Lot size - 4 decimal places
            'closed_lot_size' => 'decimal:4',

            // Price - 5 decimal places for gold
            'close_price' => 'decimal:5',

            // Pips - 2 decimal places
            'pips_profit' => 'decimal:2',

            // Money - 4 decimal places for precision
            'gross_money_profit' => 'decimal:4',
            'commission_money' => 'decimal:4',
            'swap_money' => 'decimal:4',
            'net_money_profit' => 'decimal:4',

            // Timestamp
            'closed_at' => 'datetime',
        ];
    }

    // =========================================================================
    // RELATIONSHIPS
    // =========================================================================

    /**
     * The parent trade this partial belongs to.
     */
    public function trade(): BelongsTo
    {
        return $this->belongsTo(Trade::class);
    }
}
