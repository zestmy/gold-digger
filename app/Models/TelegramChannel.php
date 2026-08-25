<?php

namespace App\Models;

use App\Services\Strategy\SignalQuality;
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
        // Overrides. Null on every one of them means "inherit"; see policy().
        'risk_percentage', 'copier_levels', 'max_trades_per_day', 'min_confluence',
        'symbols_allow', 'symbols_deny', 'read_images',
    ];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'last_message_at' => 'datetime',
            'risk_percentage' => 'float',
            'min_confluence' => 'float',
            'max_trades_per_day' => 'integer',
            'symbols_allow' => 'array',
            'symbols_deny' => 'array',
            'read_images' => 'boolean',
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

    /**
     * This channel's settings, resolved against the account's.
     *
     * Null means inherit, resolved on every read rather than copied when the channel was
     * created. A channel holding a snapshot would keep trading last month's risk
     * percentage after the account's was lowered, with nothing on screen saying so.
     *
     * @return array{
     *     risk_percentage: float,
     *     copier_levels: string,
     *     max_trades_per_day: int|null,
     *     min_confluence: float,
     *     read_images: bool,
     *     overridden: array<int, string>
     * }
     */
    public function policy(?BotSettings $settings): array
    {
        $overridden = [];

        foreach (['risk_percentage', 'copier_levels', 'max_trades_per_day', 'min_confluence', 'read_images'] as $field) {
            if ($this->{$field} !== null) {
                $overridden[] = $field;
            }
        }

        return [
            'risk_percentage' => $this->risk_percentage ?? (float) ($settings?->ai_risk_percentage ?? 0.0),
            'copier_levels' => $this->copier_levels ?? (string) ($settings?->copier_levels ?? 'provider'),
            'max_trades_per_day' => $this->max_trades_per_day ?? $settings?->ai_max_trades_per_day,
            'min_confluence' => $this->min_confluence ?? SignalQuality::MIN_CONFLUENCE,
            'read_images' => $this->read_images ?? true,
            'overridden' => $overridden,
        ];
    }

    /**
     * May this channel's signals for an instrument be traded at all?
     *
     * An allow-list wins outright when one is set: "only gold from this channel" has to
     * mean only gold, and letting a deny-list widen it again would make two settings that
     * quietly contradict each other.
     */
    public function allowsSymbol(?string $symbol): bool
    {
        if ($symbol === null || $symbol === '') {
            return true;
        }

        $symbol = strtoupper($symbol);

        $allow = array_map('strtoupper', (array) ($this->symbols_allow ?? []));

        if ($allow !== []) {
            return in_array($symbol, $allow, true);
        }

        return ! in_array($symbol, array_map('strtoupper', (array) ($this->symbols_deny ?? [])), true);
    }

    public function label(): string
    {
        return $this->title
            ?: ($this->username ? '@'.$this->username : $this->chat_id);
    }
}
