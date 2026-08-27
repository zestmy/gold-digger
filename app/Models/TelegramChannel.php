<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
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
    use BelongsToTenant;

    /** Read by the Telegram Bot API, in chats the bot itself was added to. */
    public const SOURCE_BOT = 'bot_api';

    /** Read by a collector authenticated as a user account. */
    public const SOURCE_ACCOUNT = 'mtproto';

    /** A broadcast channel. What this feature was originally only able to read. */
    public const KIND_CHANNEL = 'channel';

    /** A group or supergroup. */
    public const KIND_GROUP = 'group';

    /**
     * A bot, in a private chat.
     *
     * Listed alongside channels because a bot is a service somebody deliberately started,
     * not a person they happen to know - so naming it to the dashboard discloses nothing
     * about them. Plenty of providers deliver by bot rather than by channel, and refusing
     * to read those meant refusing a large part of the market.
     */
    public const KIND_BOT = 'bot';

    /**
     * A person, in a private chat.
     *
     * Never enumerated. `announce()` does not report these, because inventorying a
     * tenant's private correspondents into a database somebody else operates is not a
     * thing to do by default. One is registered only when its owner names it.
     */
    public const KIND_USER = 'user';

    /** Named by its owner, waiting for a signed-in client to turn it into a chat id. */
    public const RESOLVE_PENDING = 'pending';

    /** Telegram could not find it, or would not say. `resolve_error` carries why. */
    public const RESOLVE_FAILED = 'failed';

    protected $fillable = [
        'user_id', 'source', 'kind', 'chat_id', 'title', 'username',
        'resolve_state', 'resolve_error',
        'is_enabled', 'last_message_at', 'notes',
        // Overrides. Null on every one of them means "inherit"; see policy().
        'risk_percentage', 'copier_levels', 'entry_preference', 'max_trades_per_day', 'min_confluence',
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
     * Named but not yet turned into a chat id.
     *
     * Such a row cannot be watched and cannot be traded: `chat_id` is a placeholder, so no
     * incoming message will ever match it. That is the intended state - it is a request,
     * not a subscription, until a client that is actually signed in confirms the account
     * exists.
     */
    public function scopeAwaitingResolution(Builder $query): Builder
    {
        return $query->where('resolve_state', self::RESOLVE_PENDING);
    }

    public function isResolved(): bool
    {
        return $this->resolve_state === null;
    }

    /**
     * Record that a chat exists, without granting it anything.
     *
     * Called on every inbound message, so it must never widen permission: an existing row
     * keeps its `is_enabled`, and only the descriptive fields are refreshed.
     * A channel that renames itself stays the same row, which is what keeps its results
     * attached to it.
     */
    public static function register(string $source, string $chatId, ?string $title, ?string $username, int $ownerId, ?string $kind = null): self
    {
        // The owner is part of the identity, not a fallback applied to a shared row. Two
        // tenants in the same channel each get their own, which is what lets the second
        // one enable it - and what stops either from seeing the other's settings.
        $channel = static::firstOrNew([
            'user_id' => $ownerId,
            'source' => $source,
            'chat_id' => $chatId,
        ]);

        if (! $channel->exists) {
            $channel->is_enabled = false;
        }

        // Refreshed like the other descriptive fields: a chat that was registered before
        // kinds existed should stop calling itself a channel once something knows better.
        $channel->kind = $kind ?: ($channel->kind ?: self::KIND_CHANNEL);
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

        foreach (['risk_percentage', 'copier_levels', 'entry_preference', 'max_trades_per_day', 'min_confluence', 'read_images'] as $field) {
            if ($this->{$field} !== null) {
                $overridden[] = $field;
            }
        }

        return [
            'risk_percentage' => $this->risk_percentage ?? (float) ($settings?->ai_risk_percentage ?? 0.0),
            'copier_levels' => $this->copier_levels ?? (string) ($settings?->copier_levels ?? 'provider'),
            'max_trades_per_day' => $this->max_trades_per_day ?? $settings?->ai_max_trades_per_day,
            // Falls back to the account's own bar rather than straight to the constant.
            // A per-channel override existed while the account had no floor to state,
            // which meant "stricter than usual for this provider" was expressible and
            // "stricter than usual, everywhere" was not.
            'min_confluence' => $this->min_confluence ?? app(SignalQuality::class)->minConfluence($settings),
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
