<?php

namespace App\Models\Concerns;

use App\Models\Scopes\TenantScope;
use App\Models\User;
use App\Support\Tenancy\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Belongs To Tenant
 *
 * Applied to every model carrying a `user_id`, this makes tenant isolation a property of
 * the model rather than of whoever last wrote a query against it.
 *
 * Two things happen:
 *
 * - Reads are filtered to the current tenant, if there is one.
 * - Writes are stamped with the current tenant, if the caller did not name one. A row that
 *   arrives with an explicit `user_id` keeps it, which is what lets the bot API and the
 *   console write on somebody's behalf.
 *
 * Models that already declare their own `user()` relationship keep it - a method on the
 * class wins over one from a trait - so this can be applied without auditing each model's
 * existing relationships first.
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function (Model $model): void {
            if ($model->getAttribute('user_id') !== null) {
                return;
            }

            if (($tenant = Tenant::current()) !== null) {
                $model->setAttribute('user_id', $tenant);
            }
        });
    }

    /**
     * The owner. Overridden by any `user()` the model declares for itself.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Read across every tenant, deliberately.
     *
     * Named for what it costs rather than for what it does: `Trade::acrossTenants()` reads
     * as a decision in a diff, where `withoutGlobalScope(TenantScope::class)` reads as
     * boilerplate somebody copied.
     */
    public static function acrossTenants(): Builder
    {
        return static::query()->withoutGlobalScope(TenantScope::class);
    }

    /**
     * Scope to one named tenant regardless of who the current one is.
     */
    public function scopeOwnedBy(Builder $query, int|User $user): Builder
    {
        return $query
            ->withoutGlobalScope(TenantScope::class)
            ->where($this->qualifyColumn('user_id'), $user instanceof User ? $user->id : $user);
    }
}
