<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * One row per model call attempted, successful or not.
 *
 * See the migration for why attempts rather than successes, and why cost is nullable.
 */
class AiUsage extends Model
{
    use BelongsToTenant;

    protected $table = 'ai_usage';

    protected $fillable = [
        'user_id',
        'call_site',
        'model_requested',
        'model_served',
        'prompt_tokens',
        'completion_tokens',
        'cost_usd',
        'ok',
        'failure',
    ];

    protected function casts(): array
    {
        return [
            'ok' => 'boolean',
            'cost_usd' => 'decimal:6',
            'prompt_tokens' => 'integer',
            'completion_tokens' => 'integer',
        ];
    }

    /**
     * Calls made since midnight in the application's own zone.
     *
     * Deliberately not the tenant's display zone. An allowance that reset at a different
     * moment for each customer would be impossible to reason about when two of them
     * compare notes, and the number that matters here is the platform's daily bill.
     */
    public function scopeToday(Builder $query): Builder
    {
        return $query->where('created_at', '>=', now()->startOfDay());
    }

    public function scopeCallSite(Builder $query, string $site): Builder
    {
        return $query->where('call_site', $site);
    }
}
