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
            'password' => 'hashed',
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
        // Personal bot: any authenticated user can access admin
        // Future SaaS: return $this->hasRole('admin');
        return true;
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
}
