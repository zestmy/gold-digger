<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One reading of one chart, at one bar.
 *
 * See the migration for why the refusals are kept as carefully as the plans.
 */
class ChartAnalysis extends Model
{
    use BelongsToTenant;

    protected $table = 'chart_analyses';

    /** Bumped when the system prompt changes, so old readings stay attributable. */
    public const PROMPT_VERSION = 1;

    protected $fillable = [
        'user_id',
        'broker_account_id',
        'symbol',
        'timeframe',
        'bar_open_time',
        'bias',
        'plan',
        'setup_type',
        'headline',
        'structure',
        'reasoning',
        'invalidation',
        'entry_price',
        'stop_price',
        'target_price',
        'reward_ratio',
        'levels',
        'timeframes',
        'events',
        'model',
        'prompt_version',
    ];

    protected function casts(): array
    {
        return [
            'bar_open_time' => 'datetime',
            'levels' => 'array',
            'timeframes' => 'array',
            'events' => 'array',
            'entry_price' => 'decimal:6',
            'stop_price' => 'decimal:6',
            'target_price' => 'decimal:6',
            'reward_ratio' => 'decimal:2',
        ];
    }

    public function brokerAccount(): BelongsTo
    {
        return $this->belongsTo(BrokerAccount::class);
    }

    /**
     * Readings that proposed something, as opposed to readings that declined to.
     *
     * Both are worth keeping and only one is worth reviewing when the question is "did the
     * plans work" - so the split is a scope rather than a filter somebody remembers.
     */
    public function scopeActionable(Builder $query): Builder
    {
        return $query->whereIn('plan', ['buy', 'sell']);
    }

    public function scopeWaiting(Builder $query): Builder
    {
        return $query->where('plan', 'wait');
    }

    public function scopeForSymbol(Builder $query, string $symbol): Builder
    {
        return $query->where('symbol', $symbol);
    }

    /**
     * Did this reading name a complete trade?
     *
     * A plan of buy or sell with a missing level is not a trade - it is a reading whose
     * chosen level fell outside the measured list, which `ChartAnalyst::resolve()` renders
     * as null rather than as a price. Worth distinguishing before anybody scores these.
     */
    public function isComplete(): bool
    {
        return $this->plan !== 'wait'
            && $this->entry_price !== null
            && $this->stop_price !== null
            && $this->target_price !== null;
    }
}
