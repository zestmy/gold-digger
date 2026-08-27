<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Alert Model
 *
 * One incident. See the migration for why an alert has a lifetime rather than being a
 * message that gets sent.
 */
class Alert extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'user_id',
        'key',
        'level',
        'title',
        'body',
        'context',
        'first_seen_at',
        'last_seen_at',
        'resolved_at',
        'notified_at',
        'notify_count',
        'resolution_notified',
    ];

    protected function casts(): array
    {
        return [
            'context' => 'array',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'resolved_at' => 'datetime',
            'notified_at' => 'datetime',
            'notify_count' => 'integer',
            'resolution_notified' => 'boolean',
        ];
    }

    public function scopeFiring(Builder $query): Builder
    {
        return $query->whereNull('resolved_at');
    }

    public function isFiring(): bool
    {
        return $this->resolved_at === null;
    }

    /**
     * Should a message go out for this incident now?
     *
     * Once when it starts, then at most once an hour while it persists. A condition that
     * stays true for a day should not produce 1,440 messages, and one that produces a single
     * message on day one is easy to lose - so it repeats, slowly.
     */
    public function needsNotifying(int $repeatAfterMinutes = 60): bool
    {
        if (! $this->isFiring()) {
            return false;
        }

        if ($this->notified_at === null) {
            return true;
        }

        return $this->notified_at->addMinutes($repeatAfterMinutes)->isPast();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
