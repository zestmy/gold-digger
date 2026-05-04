<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Trade Model
 *
 * The core entity tracking all trading activity. Each trade record represents
 * a complete lifecycle from signal generation to final close.
 *
 * Key concepts:
 * - initial_lot_size vs remaining_lot_size: Tracks partial closes
 * - gross_pnl vs net_pnl: Shows cost impact clearly
 * - Separate cost columns: Enables detailed cost analysis
 */
class Trade extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'strategy_id',
        'broker_account_id',
        'mt5_ticket',
        'magic_number',
        'symbol',
        'direction',
        'initial_lot_size',
        'remaining_lot_size',
        'entry_price',
        'sl_price',
        'tp1_price',
        'tp2_price',
        'tp3_price',
        'entry_spread_pips',
        'entry_spread_money',
        'commission_money',
        'swap_money',
        'slippage_pips',
        'gross_pnl_pips',
        'gross_pnl_money',
        'net_pnl_money',
        'status',
        'closure_reason',
        'notes',
        'opened_at',
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
            // Lot sizes - 4 decimal places (0.0001 lots = micro lot)
            'initial_lot_size' => 'decimal:4',
            'remaining_lot_size' => 'decimal:4',

            // Prices - 5 decimal places for gold (XAUUSD)
            'entry_price' => 'decimal:5',
            'sl_price' => 'decimal:5',
            'tp1_price' => 'decimal:5',
            'tp2_price' => 'decimal:5',
            'tp3_price' => 'decimal:5',

            // Pips - 2 decimal places
            'entry_spread_pips' => 'decimal:2',
            'slippage_pips' => 'decimal:2',
            'gross_pnl_pips' => 'decimal:2',

            // Money - 4 decimal places for costs, 2 for P&L
            'entry_spread_money' => 'decimal:4',
            'commission_money' => 'decimal:4',
            'swap_money' => 'decimal:4',
            'gross_pnl_money' => 'decimal:2',
            'net_pnl_money' => 'decimal:2',

            // Timestamps
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    // =========================================================================
    // RELATIONSHIPS
    // =========================================================================

    /**
     * The user who owns this trade.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The strategy that generated this trade.
     */
    public function strategy(): BelongsTo
    {
        return $this->belongsTo(Strategy::class);
    }

    /**
     * The broker account where this trade was executed.
     */
    public function brokerAccount(): BelongsTo
    {
        return $this->belongsTo(BrokerAccount::class);
    }

    /**
     * Partial closes for this trade.
     * Each TP hit creates a new partial record.
     */
    public function partials(): HasMany
    {
        return $this->hasMany(TradePartial::class);
    }

    /**
     * Screenshots captured for this trade.
     * Entry, exit, and milestone screenshots.
     */
    public function screenshots(): HasMany
    {
        return $this->hasMany(TradeScreenshot::class);
    }

    /**
     * Logs related to this specific trade.
     */
    public function logs(): HasMany
    {
        return $this->hasMany(BotLog::class, 'related_trade_id');
    }

    // =========================================================================
    // HELPER METHODS
    // =========================================================================

    /**
     * Check if the trade is still open (has remaining position).
     */
    public function isOpen(): bool
    {
        return in_array($this->status, ['pending', 'open', 'partially_closed']);
    }

    /**
     * Calculate total costs (spread + commission + swap).
     */
    public function getTotalCosts(): float
    {
        return (float) $this->entry_spread_money
            + (float) $this->commission_money
            + (float) $this->swap_money;
    }
}
