<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One run of the strategy improver.
 *
 * See the migration for why runs are kept rather than shown once.
 */
class StrategyImprovement extends Model
{
    public const STATUS_QUEUED = 'queued';

    public const STATUS_RUNNING = 'running';

    public const STATUS_DONE = 'done';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'user_id',
        'strategy_id',
        'status',
        'options',
        'baseline',
        'proposed',
        'proposals',
        'thin',
        'verdict',
        'model',
        'error',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'options' => 'array',
            'baseline' => 'array',
            'proposed' => 'array',
            'proposals' => 'array',
            'thin' => 'boolean',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function strategy(): BelongsTo
    {
        return $this->belongsTo(Strategy::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isFinished(): bool
    {
        return in_array($this->status, [self::STATUS_DONE, self::STATUS_FAILED], true);
    }

    /**
     * Did the proposals beat what is running today?
     *
     * Deliberately returns false on a thin sample regardless of the numbers. A better
     * expectancy across nine trades is not an improvement, it is a coin landing the same
     * way twice, and a green badge saying otherwise is how it ends up applied.
     */
    public function beatsBaseline(): bool
    {
        if ($this->thin || $this->status !== self::STATUS_DONE) {
            return false;
        }

        return (float) ($this->proposed['expectancy'] ?? 0) > (float) ($this->baseline['expectancy'] ?? 0);
    }
}
