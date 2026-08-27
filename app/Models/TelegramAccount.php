<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
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
    use BelongsToTenant;

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
        'session', 'is_hosted', 'ingest_state',
    ];

    /**
     * The session never leaves the server in a response a person can see.
     *
     * It is not a token that names an account: it can read every chat and post as the
     * user. Hiding it here means a careless `toArray()` in a Livewire payload cannot
     * put it in front of a browser, which is the way this sort of column usually leaks.
     */
    protected $hidden = ['session'];

    protected function casts(): array
    {
        return [
            'last_seen_at' => 'datetime',
            'login_updated_at' => 'datetime',
            'is_hosted' => 'boolean',
            'ingest_state' => 'array',
            // APP_KEY, so a database dump on its own is not a set of working logins.
            'session' => 'encrypted',
        ];
    }

    /**
     * A new account follows the deployment's setting unless told otherwise.
     *
     * The column defaults to hosted so a direct insert lands on the supported path, but
     * the configured answer has to win wherever a row is actually made - otherwise
     * `hosted_by_default` means "hosted, except through the six other ways an account
     * gets created", which is not a setting anyone can reason about.
     */
    protected static function booted(): void
    {
        static::creating(function (self $account) {
            if ($account->is_hosted === null) {
                $account->is_hosted = (bool) config('telegram.hosted_by_default', true);
            }
        });
    }

    /**
     * Accounts the platform signs in and runs, as opposed to ones a tenant runs.
     */
    public function scopeHosted($query)
    {
        return $query->where('is_hosted', true);
    }

    /**
     * Is this account's session established and usable?
     */
    public function isSignedIn(): bool
    {
        return $this->login_state === self::ACTIVE && filled($this->session);
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
