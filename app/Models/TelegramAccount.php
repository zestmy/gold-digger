<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A Telegram account a collector signs in as.
 *
 * The dashboard never holds the session. What it holds is a name, a token that collector
 * authenticates with, and whatever the collector reports about itself once it is running -
 * enough to answer "which sign-in expired" without ever being able to read a chat.
 */
class TelegramAccount extends Model
{
    /** Beyond this with no contact, a collector is not running. */
    private const STALE_MINUTES = 10;

    /** States the sign-in conversation can be in. */
    public const IDLE = 'idle';

    public const REQUESTED = 'requested';

    public const CODE_SENT = 'code_sent';

    public const CODE_SUBMITTED = 'code_submitted';

    public const PASSWORD_NEEDED = 'password_needed';

    public const PASSWORD_SUBMITTED = 'password_submitted';

    public const ACTIVE = 'active';

    public const FAILED = 'failed';

    /** A sign-in that has sat in one state longer than this has stalled. */
    private const LOGIN_TIMEOUT_MINUTES = 10;

    protected $fillable = [
        'user_id', 'label', 'telegram_username', 'display_name', 'bot_token_id', 'last_seen_at',
        'login_state', 'login_phone', 'login_message', 'login_updated_at',
    ];

    protected function casts(): array
    {
        return [
            'last_seen_at' => 'datetime',
            'login_updated_at' => 'datetime',
        ];
    }

    /**
     * Is a sign-in conversation under way?
     */
    public function loggingIn(): bool
    {
        return ! in_array($this->login_state, [self::IDLE, self::ACTIVE, self::FAILED], true);
    }

    /**
     * Has one been left half-finished?
     *
     * A code expires and a person walks away. Without this the page would offer to wait for
     * ever on a conversation nothing is going to continue.
     */
    public function loginStalled(): bool
    {
        return $this->loggingIn()
            && $this->login_updated_at !== null
            && $this->login_updated_at->lt(now()->subMinutes(self::LOGIN_TIMEOUT_MINUTES));
    }

    /**
     * Move the conversation on, timestamping so a stall is detectable.
     */
    public function advance(string $state, ?string $message = null): void
    {
        $this->update([
            'login_state' => $state,
            'login_message' => $message,
            'login_updated_at' => now(),
        ]);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function token(): BelongsTo
    {
        return $this->belongsTo(BotToken::class, 'bot_token_id');
    }

    public function channels(): HasMany
    {
        return $this->hasMany(TelegramChannel::class);
    }

    /**
     * Has a collector been in touch recently?
     *
     * "Never seen" and "seen an hour ago" are both not-connected, and they mean completely
     * different things: one has not been set up, the other has stopped. The page shows the
     * timestamp for exactly that reason.
     */
    public function isConnected(): bool
    {
        return $this->last_seen_at !== null
            && $this->last_seen_at->gt(now()->subMinutes(self::STALE_MINUTES));
    }

    public function name(): string
    {
        return $this->display_name
            ?: ($this->telegram_username ? '@'.$this->telegram_username : $this->label);
    }
}
