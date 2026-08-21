<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Bot Heartbeat Model
 *
 * Last known state of an executor. One row per user+source, overwritten on each poll.
 * This is what BotStatusCard reads instead of its hardcoded $isOnline = false.
 */
class BotHeartbeat extends Model
{
    /**
     * Seconds before an executor is considered offline.
     *
     * Three missed polls at the EA's default 5-second interval, with room for a slow
     * WebRequest. Short enough that a dead terminal is visible within a minute.
     */
    public const STALE_AFTER_SECONDS = 45;

    protected $fillable = [
        'user_id',
        'broker_account_id',
        'source',
        'version',
        'terminal_build',
        'algo_trading_enabled',
        'broker_connected',
        'resolved_symbol',
        'pip_size',
        'digits',
        'pip_value_per_lot',
        'balance',
        'equity',
        'margin_free',
        'open_positions',
        'last_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'algo_trading_enabled' => 'boolean',
            'broker_connected' => 'boolean',
            'pip_size' => 'decimal:5',
            'digits' => 'integer',
            'pip_value_per_lot' => 'decimal:5',
            'balance' => 'decimal:2',
            'equity' => 'decimal:2',
            'margin_free' => 'decimal:2',
            'open_positions' => 'integer',
            'terminal_build' => 'integer',
            'last_seen_at' => 'datetime',
        ];
    }

    /**
     * Is this executor currently alive?
     */
    public function isOnline(): bool
    {
        return $this->last_seen_at !== null
            && $this->last_seen_at->diffInSeconds(now()) < self::STALE_AFTER_SECONDS;
    }

    /**
     * Is the executor reachable but unable to trade?
     *
     * This is the state worth surfacing loudly: the heartbeat still arrives, so the
     * dashboard looks healthy, but Algo Trading is off in the terminal and every order
     * will come back 10027. Without this flag it presents as "the bot just never
     * trades", which is exactly the diagnosis that costs an afternoon.
     */
    public function isOnlineButBlocked(): bool
    {
        return $this->isOnline() && (! $this->algo_trading_enabled || ! $this->broker_connected);
    }

    /**
     * Human-readable reason the executor cannot trade, or null when it can.
     */
    public function blockedReason(): ?string
    {
        if (! $this->isOnline()) {
            return 'No heartbeat - the terminal or EA is not running.';
        }

        if (! $this->broker_connected) {
            return 'The terminal has lost its connection to the broker.';
        }

        if (! $this->algo_trading_enabled) {
            return 'Algo Trading is disabled in the terminal. Click the Algo Trading button in MT5.';
        }

        return null;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function brokerAccount(): BelongsTo
    {
        return $this->belongsTo(BrokerAccount::class);
    }
}
