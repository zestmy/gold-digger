<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Signal Outcome
 *
 * The forward path of one signal, measured from the bars that arrived after it. See the
 * migration for why this exists and App\Services\Outcomes\OutcomeTracker for how a row is
 * advanced.
 *
 * ## Status is decided by the first level touched
 *
 * `won` means the first target was reached before the stop; `lost` means the stop was
 * reached first; `expired` means the horizon passed with neither. That is a statement
 * about the levels the signal published, not about any position - a trade managed with a
 * break-even stop can lose on a `won` signal and vice versa. It is the honest unit for
 * judging the signal itself, which is the thing being measured here.
 */
class SignalOutcome extends Model
{
    use BelongsToTenant;

    public const OPEN = 'open';

    public const WON = 'won';

    public const LOST = 'lost';

    public const EXPIRED = 'expired';

    public const SOURCE_AI = 'ai';

    public const SOURCE_COPIED = 'copied';

    protected $fillable = [
        'user_id', 'subject_type', 'subject_id', 'source', 'broker_account_id',
        'symbol', 'timeframe', 'direction',
        'reference_price', 'stop_price', 'tp1_price', 'tp2_price', 'tp3_price', 'risk',
        'started_at', 'last_bar_at', 'bars_seen', 'horizon_bars',
        'mfe_r', 'mae_r', 'tp1_bars', 'tp2_bars', 'tp3_bars', 'sl_bars', 'first_hit',
        'r_at_1', 'r_at_5', 'r_at_20',
        'status', 'resolved_at', 'context',
    ];

    protected function casts(): array
    {
        return [
            'reference_price' => 'float',
            'stop_price' => 'float',
            'tp1_price' => 'float',
            'tp2_price' => 'float',
            'tp3_price' => 'float',
            'risk' => 'float',
            'mfe_r' => 'float',
            'mae_r' => 'float',
            'r_at_1' => 'float',
            'r_at_5' => 'float',
            'r_at_20' => 'float',
            'started_at' => 'datetime',
            'last_bar_at' => 'datetime',
            'resolved_at' => 'datetime',
            'context' => 'array',
        ];
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function brokerAccount(): BelongsTo
    {
        return $this->belongsTo(BrokerAccount::class);
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', self::OPEN);
    }

    /**
     * Verdict in: the first level has been touched, or the horizon passed.
     */
    public function scopeDecided(Builder $query): Builder
    {
        return $query->where('status', '!=', self::OPEN);
    }

    /**
     * Still being walked. Distinct from "no verdict yet": a won signal keeps walking for
     * its later rungs until the stop, the last rung or the horizon.
     */
    public function scopeUnresolved(Builder $query): Builder
    {
        return $query->whereNull('resolved_at');
    }

    public function isBuy(): bool
    {
        return $this->direction === 'buy';
    }

    /**
     * A price as a multiple of the risk, signed in the trade's favour.
     */
    public function r(float $price): float
    {
        if ($this->risk <= 0.0) {
            return 0.0;
        }

        $move = $price - $this->reference_price;

        return round(($this->isBuy() ? $move : -$move) / $this->risk, 4);
    }

    /**
     * The R the first target pays, if there is one.
     */
    public function tp1R(): ?float
    {
        return $this->tp1_price === null ? null : abs($this->r($this->tp1_price));
    }

    /**
     * One line for a feed row: what happened, and how quickly.
     */
    public function summary(): ?string
    {
        return match ($this->status) {
            self::WON => 'TP1 in '.$this->bars($this->tp1_bars),
            self::LOST => 'Stopped in '.$this->bars($this->sl_bars),
            self::EXPIRED => 'Neither level in '.$this->bars($this->horizon_bars),
            default => $this->bars_seen === 0 ? null : 'Open · '.$this->bars($this->bars_seen).' so far',
        };
    }

    private function bars(?int $n): string
    {
        return $n === null ? '?' : ($n === 1 ? '1 bar' : "{$n} bars");
    }
}
