<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Symbol Spec Model
 *
 * What the terminal knows about one instrument on one account. See the migration for why this
 * is no longer a handful of columns on the heartbeat.
 */
class SymbolSpec extends Model
{
    protected $fillable = [
        'broker_account_id',
        'base_symbol',
        'symbol',
        'pip_size',
        'digits',
        'pip_value_per_lot',
        'volume_min',
        'volume_step',
        'last_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'pip_size' => 'decimal:5',
            'digits' => 'integer',
            'pip_value_per_lot' => 'decimal:5',
            'volume_min' => 'decimal:4',
            'volume_step' => 'decimal:4',
            'last_seen_at' => 'datetime',
        ];
    }

    /**
     * The specification a strategy's symbol resolves to on an account.
     *
     * Matched on the base name first, since that is what a strategy names. Falling back to the
     * resolved name covers the case where somebody has configured a strategy with the broker's
     * own suffixed name rather than the generic one - which is a reasonable thing to do, and
     * would otherwise silently find nothing.
     */
    public static function resolve(?int $brokerAccountId, string $symbol): ?self
    {
        if ($brokerAccountId === null) {
            return null;
        }

        return self::query()
            ->where('broker_account_id', $brokerAccountId)
            ->where(fn ($q) => $q->where('base_symbol', $symbol)->orWhere('symbol', $symbol))
            // A base-name match is the intended one; the suffixed fallback is a convenience.
            ->orderByRaw('CASE WHEN base_symbol = ? THEN 0 ELSE 1 END', [$symbol])
            ->first();
    }

    /**
     * Is this specification complete enough to size a position from?
     *
     * Pip size turns configured pip distances into prices; pip value turns a risk percentage
     * into lots. Without both, the strategy layer records the signal and refuses to trade it
     * rather than guessing - which is the whole reason these values come from the terminal.
     */
    public function isComplete(): bool
    {
        return $this->pip_size !== null
            && (float) $this->pip_size > 0
            && $this->pip_value_per_lot !== null
            && (float) $this->pip_value_per_lot > 0;
    }

    public function brokerAccount(): BelongsTo
    {
        return $this->belongsTo(BrokerAccount::class);
    }
}
