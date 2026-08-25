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

    protected $fillable = [
        'user_id', 'label', 'telegram_username', 'display_name', 'bot_token_id', 'last_seen_at',
    ];

    protected function casts(): array
    {
        return ['last_seen_at' => 'datetime'];
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
