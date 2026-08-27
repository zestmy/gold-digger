<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Bot Token Model
 *
 * API credential for an executor (the MQL5 EA, or any future adapter).
 *
 * SECURITY: only the SHA-256 hash is persisted. `generate()` returns the plaintext
 * once; there is no way to recover it afterwards. Lookup is by hash, so reading the
 * database yields nothing usable.
 */
class BotToken extends Model
{
    use BelongsToTenant;

    /** Prefix makes the credential recognisable in logs and secret scanners. */
    public const PREFIX = 'gd_';

    protected $fillable = [
        'user_id',
        'name',
        'token_hash',
        'broker_account_id',
        'last_used_at',
        'expires_at',
        'revoked_at',
    ];

    /**
     * Never let the hash leak through a JSON response or a debug dump.
     *
     * @var list<string>
     */
    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'last_used_at' => 'datetime',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    // =========================================================================
    // TOKEN LIFECYCLE
    // =========================================================================

    /**
     * Hash a plaintext token for storage or lookup.
     *
     * SHA-256 rather than bcrypt: this runs on every polled request, and the input is
     * 32 bytes of CSPRNG output rather than a guessable human password, so the slow
     * hashing that protects passwords buys nothing here.
     */
    public static function hash(string $plaintext): string
    {
        return hash('sha256', $plaintext);
    }

    /**
     * Issue a new token.
     *
     * @return array{0: string, 1: self} the plaintext (show it once) and the record
     */
    public static function generate(User $user, string $name, ?BrokerAccount $account = null): array
    {
        $plaintext = self::PREFIX.Str::random(48);

        $token = self::create([
            'user_id' => $user->id,
            'name' => $name,
            'token_hash' => self::hash($plaintext),
            'broker_account_id' => $account?->id,
        ]);

        return [$plaintext, $token];
    }

    /**
     * Resolve a plaintext token to a usable record, or null.
     *
     * Returns null for unknown, revoked and expired tokens alike - the caller cannot
     * distinguish them, which keeps the 401 response from confirming that a token
     * once existed.
     */
    public static function resolve(string $plaintext): ?self
    {
        // Across tenants deliberately. Resolving a credential is what establishes who the
        // tenant is, so it cannot itself be filtered by one - and a token presented while
        // some other identity happens to be current must still resolve to its real owner
        // rather than silently to nothing.
        $token = self::acrossTenants()->where('token_hash', self::hash($plaintext))->first();

        if ($token === null || ! $token->isUsable()) {
            return null;
        }

        return $token;
    }

    public function isUsable(): bool
    {
        if ($this->revoked_at !== null) {
            return false;
        }

        return $this->expires_at === null || $this->expires_at->isFuture();
    }

    /**
     * Record use without bumping updated_at on every poll.
     */
    public function touchLastUsed(): void
    {
        $this->newQuery()
            ->whereKey($this->getKey())
            ->update(['last_used_at' => now()]);
    }

    // =========================================================================
    // RELATIONSHIPS
    // =========================================================================

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function brokerAccount(): BelongsTo
    {
        return $this->belongsTo(BrokerAccount::class);
    }
}
