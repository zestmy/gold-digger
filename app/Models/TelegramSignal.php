<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A message captured from Telegram, and what became of it.
 *
 * See the migration for why unparsed messages are kept: a provider changing their format
 * is otherwise completely silent.
 */
class TelegramSignal extends Model
{
    public const PARSE_PENDING = 'pending';

    public const PARSE_OK = 'parsed';

    public const PARSE_FAILED = 'unparsed';

    public const REVIEW_PENDING = 'pending';

    public const REVIEW_APPROVED = 'approved';

    public const REVIEW_DECLINED = 'declined';

    /** Never reviewed, because it was never going to be traded. */
    public const REVIEW_SKIPPED = 'skipped';

    public const EXEC_NONE = 'not_attempted';

    public const EXEC_QUEUED = 'queued';

    public const EXEC_EXECUTED = 'executed';

    public const EXEC_BLOCKED = 'blocked';

    public const EXEC_FAILED = 'failed';

    protected $fillable = [
        'user_id', 'source', 'external_id', 'chat_title', 'chat_id', 'raw_text', 'posted_at',
        'parse_status', 'parse_error', 'symbol', 'direction', 'entry_price', 'entry_zone_high',
        'sl_price', 'tp_prices',
        'review_status', 'review_reasoning', 'review_confidence', 'review_model', 'reviewed_at',
        'execution_status', 'execution_note', 'trade_id',
    ];

    protected function casts(): array
    {
        return [
            'posted_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'tp_prices' => 'array',
            'entry_price' => 'float',
            'entry_zone_high' => 'float',
            'sl_price' => 'float',
            'review_confidence' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function trade(): BelongsTo
    {
        return $this->belongsTo(Trade::class);
    }

    public function scopeAwaitingReview(Builder $query): Builder
    {
        return $query->where('parse_status', self::PARSE_OK)
            ->where('review_status', self::REVIEW_PENDING);
    }

    /**
     * Is this one still a candidate for execution?
     *
     * Parsed, approved, and not already acted on. Anything else is history.
     */
    public function isActionable(): bool
    {
        return $this->parse_status === self::PARSE_OK
            && $this->review_status === self::REVIEW_APPROVED
            && $this->execution_status === self::EXEC_NONE;
    }
}
