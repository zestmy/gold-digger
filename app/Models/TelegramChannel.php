<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A Telegram chat the copier listens to.
 *
 * ## Registration is not permission
 *
 * A collector running as a real Telegram account can see every channel that account is a
 * member of, and it registers what it sees so the list is honest about what is reachable.
 * `is_enabled` is a separate, deliberate act. Nothing in this codebase sets it - joining
 * a channel to read it must not be the same gesture as trading it.
 */
class TelegramChannel extends Model
{
    /** Read by the Telegram Bot API, in chats the bot itself was added to. */
    public const SOURCE_BOT = 'bot_api';

    /** Read by a collector authenticated as a user account. */
    public const SOURCE_ACCOUNT = 'mtproto';

    protected $fillable = [
        'user_id', 'source', 'chat_id', 'title', 'username',
        'is_enabled', 'last_message_at', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'last_message_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function signals(): HasMany
    {
        return $this->hasMany(TelegramSignal::class);
    }

    public function scopeEnabled(Builder $query): Builder
    {
        return $query->where('is_enabled', true);
    }

    /**
     * Record that a chat exists, without granting it anything.
     *
     * Called on every inbound message, so it must never widen permission: an existing row
     * keeps its `is_enabled` and its owner, and only the descriptive fields are refreshed.
     * A channel that renames itself stays the same row, which is what keeps its results
     * attached to it.
     */
    public static function register(string $source, string $chatId, ?string $title, ?string $username, int $fallbackUserId): self
    {
        $channel = static::firstOrNew(['source' => $source, 'chat_id' => $chatId]);

        if (! $channel->exists) {
            $channel->user_id = $fallbackUserId;
            $channel->is_enabled = false;
        }

        $channel->title = $title ?: $channel->title;
        $channel->username = $username ?: $channel->username;
        $channel->last_message_at = now();
        $channel->save();

        return $channel;
    }

    public function label(): string
    {
        return $this->title
            ?: ($this->username ? '@'.$this->username : $this->chat_id);
    }
}
