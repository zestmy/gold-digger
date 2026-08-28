<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Hash;

/**
 * User Model
 *
 * The central user entity. In this personal bot setup, there's typically one user,
 * but the schema supports multi-tenant for future SaaS expansion.
 *
 * Implements FilamentUser to control admin panel access.
 * Currently allows all authenticated users (personal use).
 * For SaaS: Add role checks in canAccessPanel().
 */
class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'timezone',
        'telegram_chat_id',
        'alerts_enabled',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        // A careless toArray() in a Livewire payload is how this sort of column reaches a
        // browser, and either of these is enough to sign in as somebody.
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'is_admin' => 'boolean',
            'alerts_enabled' => 'boolean',
            'password' => 'hashed',
            // The shared secret generates valid codes for ever, so it is protected the way
            // broker account numbers and Telegram sessions are.
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'array',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    // =========================================================================
    // FILAMENT INTEGRATION
    // =========================================================================

    /**
     * Determine if the user can access the Filament admin panel.
     *
     * For personal use: All authenticated users can access.
     * For SaaS: Add role/permission checks here.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        // Was `return true`, which meant every registered account could read and edit every
        // other account's trading data - no Filament resource scopes its query by user, so
        // reaching the panel is reaching all of it.
        //
        // Granted with: php artisan user:admin you@example.com
        return (bool) $this->is_admin;
    }

    // =========================================================================
    // RELATIONSHIPS
    // =========================================================================

    /**
     * User's bot settings (one-to-one).
     * Auto-created via UserObserver when user registers.
     */
    public function botSettings(): HasOne
    {
        return $this->hasOne(BotSettings::class);
    }

    /**
     * User's broker accounts (one-to-many).
     * A user can have multiple MT5 accounts (demo, live, different brokers).
     */
    public function brokerAccounts(): HasMany
    {
        return $this->hasMany(BrokerAccount::class);
    }

    /**
     * User's trading strategies (one-to-many).
     * Each strategy has its own parameters and can be activated/deactivated.
     */
    public function strategies(): HasMany
    {
        return $this->hasMany(Strategy::class);
    }

    /**
     * User's trades (one-to-many).
     * All trades across all broker accounts and strategies.
     */
    public function trades(): HasMany
    {
        return $this->hasMany(Trade::class);
    }

    /**
     * User's daily summaries (one-to-many).
     * Pre-aggregated daily stats for fast dashboard loading.
     */
    public function dailySummaries(): HasMany
    {
        return $this->hasMany(DailySummary::class);
    }

    /**
     * The zone this person's screen should read in.
     *
     * Falls back to the application's own, which is UTC - so an unset preference renders
     * exactly what the database holds, and nothing has to special-case null downstream.
     */
    public function zone(): string
    {
        return $this->timezone ?: (string) config('app.timezone', 'UTC');
    }

    /**
     * Has this person actually chosen, as opposed to never having looked?
     *
     * Worth distinguishing: only one of those two wants to be asked.
     */
    public function hasChosenZone(): bool
    {
        return filled($this->timezone);
    }

    // =========================================================================
    // TWO-FACTOR
    // =========================================================================

    /**
     * Is a second factor actually in force?
     *
     * Confirmed, not merely issued. A secret nobody has proved they hold would lock the
     * account out of itself, which is the opposite of what enabling this is for.
     */
    public function hasTwoFactor(): bool
    {
        return $this->two_factor_secret !== null && $this->two_factor_confirmed_at !== null;
    }

    /**
     * Spend one recovery code, if it matches.
     *
     * Single use, and removed on use rather than marked - a code that is gone cannot be
     * replayed, and there is nothing about a spent one worth keeping.
     *
     * Compared against hashes, so this walks the list rather than looking one up. Eight
     * entries makes that a non-question.
     */
    public function useRecoveryCode(string $code): bool
    {
        $code = trim(strtolower($code));
        $remaining = [];
        $used = false;

        foreach ((array) ($this->two_factor_recovery_codes ?? []) as $hash) {
            if (! $used && Hash::check($code, $hash)) {
                $used = true;

                continue;
            }

            $remaining[] = $hash;
        }

        if ($used) {
            $this->forceFill(['two_factor_recovery_codes' => $remaining])->save();
        }

        return $used;
    }

    /**
     * How many ways back in are left.
     *
     * Worth surfacing: somebody down to their last code should be told to regenerate
     * before they are down to none.
     */
    public function recoveryCodesRemaining(): int
    {
        return count((array) ($this->two_factor_recovery_codes ?? []));
    }
}
