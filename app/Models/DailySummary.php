<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Daily Summary Model
 *
 * Pre-aggregated daily statistics per user per broker account.
 * Functions like a materialized view, updated at end of each trading day.
 *
 * WHY pre-aggregate?
 * - Dashboard loads instantly without scanning all trades
 * - Historical accuracy (point-in-time balance snapshots)
 * - Complex metrics (drawdown) are expensive to compute live
 */
class DailySummary extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'broker_account_id',
        'date',
        'trades_count',
        'wins_count',
        'losses_count',
        'gross_pnl_money',
        'total_costs_money',
        'net_pnl_money',
        'max_drawdown_money',
        'starting_balance',
        'ending_balance',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            // Date without time
            'date' => 'date',

            // Money - 2 decimal places
            'gross_pnl_money' => 'decimal:2',
            'total_costs_money' => 'decimal:2',
            'net_pnl_money' => 'decimal:2',
            'max_drawdown_money' => 'decimal:2',
            'starting_balance' => 'decimal:2',
            'ending_balance' => 'decimal:2',
        ];
    }

    // =========================================================================
    // RELATIONSHIPS
    // =========================================================================

    /**
     * The user this summary belongs to.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The broker account this summary covers.
     */
    public function brokerAccount(): BelongsTo
    {
        return $this->belongsTo(BrokerAccount::class);
    }

    // =========================================================================
    // COMPUTED PROPERTIES
    // =========================================================================

    /**
     * Calculate win rate as percentage.
     */
    public function getWinRateAttribute(): float
    {
        if ($this->trades_count === 0) {
            return 0;
        }

        return round(($this->wins_count / $this->trades_count) * 100, 2);
    }

    /**
     * Calculate daily return percentage.
     */
    public function getDailyReturnPercentAttribute(): float
    {
        if ($this->starting_balance == 0) {
            return 0;
        }

        return round((($this->ending_balance - $this->starting_balance) / $this->starting_balance) * 100, 2);
    }
}
