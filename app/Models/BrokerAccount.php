<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Broker Account Model
 *
 * Represents an MT5 broker account connection. Users can have multiple
 * broker accounts (demo vs live, different brokers).
 *
 * SECURITY: account_number is encrypted at rest using Laravel's encrypted cast.
 * This means even if the database is compromised, account numbers are protected.
 */
class BrokerAccount extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'label',
        'broker_name',
        'account_number',
        'server',
        'is_demo',
        'is_active',
        'account_currency',
        'leverage',
        'last_balance',
        'last_equity',
        'last_synced_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            // SECURITY: Encrypt account number at rest
            // Laravel automatically encrypts on save, decrypts on read
            'account_number' => 'encrypted',

            // Booleans
            'is_demo' => 'boolean',
            'is_active' => 'boolean',

            // Decimals for financial precision
            'last_balance' => 'decimal:2',
            'last_equity' => 'decimal:2',

            // Timestamp for last sync
            'last_synced_at' => 'datetime',
        ];
    }

    // =========================================================================
    // RELATIONSHIPS
    // =========================================================================

    /**
     * The user who owns this broker account.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * All trades executed on this broker account.
     */
    public function trades(): HasMany
    {
        return $this->hasMany(Trade::class);
    }

    /**
     * Daily summaries for this broker account.
     */
    public function dailySummaries(): HasMany
    {
        return $this->hasMany(DailySummary::class);
    }
}
