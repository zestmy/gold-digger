<?php

namespace App\Models\Scopes;

use App\Support\Tenancy\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Tenant Scope
 *
 * Adds `where user_id = <current tenant>` to every query on a model that belongs to one.
 *
 * The scope is a no-op when there is no current tenant - see `Tenant` for why that is a
 * deliberate choice rather than a hole. Qualifying the column matters: several of these
 * models are joined against each other, and an unqualified `user_id` in a join is an
 * ambiguous-column error rather than a wrong answer, which is at least loud.
 */
class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $tenant = Tenant::current();

        if ($tenant === null) {
            return;
        }

        $builder->where($model->qualifyColumn('user_id'), $tenant);
    }
}
