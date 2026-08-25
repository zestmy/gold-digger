<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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

    /** A message that carries its own trade. */
    public const KIND_SIGNAL = 'signal';

    /** A reply telling you what to do with a position already open. */
    public const KIND_FOLLOW_UP = 'follow_up';

    // ---- follow-up actions ----------------------------------------------
    // A closed set on purpose. The model turns a sentence into one of these and nothing
    // downstream ever reads prose, so a provider writing something novel produces an
    // unrecognised action rather than a creative trade.

    /** Nothing to do - encouragement, commentary, a screenshot. */
    public const FOLLOW_NONE = 'none';

    /** Take part of the position off. */
    public const FOLLOW_PARTIAL = 'secure_partial';

    /** Move the stop to the entry. */
    public const FOLLOW_BREAKEVEN = 'breakeven';

    /** Close what remains. */
    public const FOLLOW_CLOSE = 'close';

    /** Move the stop to a named level. */
    public const FOLLOW_MOVE_STOP = 'move_stop';

    /** Another entry on the same idea. The only one that can increase risk. */
    public const FOLLOW_ADD = 'add_entry';

    protected $fillable = [
        'user_id', 'source', 'kind', 'external_id', 'reply_to_message_id', 'parent_signal_id',
        'chat_title', 'chat_id', 'telegram_channel_id', 'raw_text', 'posted_at',
        'parse_status', 'parse_error', 'symbol', 'direction', 'entry_price', 'entry_zone_high',
        'sl_price', 'tp_prices',
        'follow_up_action', 'follow_up_fraction', 'follow_up_price',
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
            'follow_up_fraction' => 'float',
            'follow_up_price' => 'float',
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

    public function channel(): BelongsTo
    {
        return $this->belongsTo(TelegramChannel::class, 'telegram_channel_id');
    }

    /** The signal this message is a reply to, if it was captured. */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_signal_id');
    }

    public function followUps(): HasMany
    {
        return $this->hasMany(self::class, 'parent_signal_id');
    }

    public function isFollowUp(): bool
    {
        return $this->kind === self::KIND_FOLLOW_UP;
    }

    /**
     * Can this instruction still be carried out?
     *
     * Needs a parent that actually became a position. A follow-up to a signal the copier
     * declined is a real instruction about a trade this account never took, and acting on
     * it would mean managing somebody else's position.
     */
    public function isActionableFollowUp(): bool
    {
        return $this->isFollowUp()
            && $this->execution_status === self::EXEC_NONE
            && $this->follow_up_action !== null
            && $this->follow_up_action !== self::FOLLOW_NONE
            && $this->parent?->trade !== null;
    }

    public function scopeAwaitingInterpretation(Builder $query): Builder
    {
        return $query->where('kind', self::KIND_FOLLOW_UP)
            ->whereNull('follow_up_action')
            ->whereNotNull('parent_signal_id');
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
