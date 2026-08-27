<?php

namespace App\Services\Ai;

use App\Models\AiUsage;
use App\Models\BotSettings;
use App\Support\Tenancy\Tenant;

/**
 * AI Spend
 *
 * What a tenant is still allowed to ask a model, and what it cost when they did.
 *
 * ## Why this is separate from AiFund
 *
 * They bound different quantities for different payers, and conflating them would make
 * both wrong. `AiFund` caps what autonomous trading may *lose* - the tenant's money, in
 * their broker account, recovered by closing positions. This caps what inference may
 * *spend* - the platform's money, on an API key the tenant never sees, unrecoverable.
 *
 * A tenant with a healthy fund can be out of calls, and a tenant with plenty of calls can
 * have an exhausted fund. Both are normal, and each says something different about what to
 * do next, so they are reported separately rather than merged into one "AI is off".
 *
 * ## The limit is a ceiling on requests, not on cost
 *
 * Cost per call varies with model and prompt length, so a currency budget would be the
 * more accurate control. It is not the one used here, because enforcing it means knowing a
 * call's price *before* making it, and that is not knowable - the completion length is
 * decided by the model. A request count is enforceable at the moment the decision has to
 * be made, and the recorded cost is what turns it into a price later.
 *
 * So: counts gate, costs inform. Pricing a plan is a question for the cost column.
 */
final class AiSpend
{
    /**
     * Calls a tenant may make in a day when nothing more specific is set.
     *
     * Read from config so the platform default moves without a deploy per tenant, and
     * overridden per tenant by `bot_settings.ai_daily_call_limit` - which is where a paid
     * plan's entitlement will eventually be written.
     */
    public function limitFor(?int $userId): int
    {
        $default = (int) config('ai.limits.daily_calls', 200);

        if ($userId === null) {
            return $default;
        }

        $configured = BotSettings::acrossTenants()
            ->where('user_id', $userId)
            ->value('ai_daily_call_limit');

        // Null means "follow the platform"; zero is a real answer meaning no AI at all,
        // which is why this is a null check and not a falsy one.
        return $configured === null ? $default : (int) $configured;
    }

    /**
     * @return array{limit: int, used: int, remaining: int, exhausted: bool}
     */
    public function allowance(?int $userId): array
    {
        $limit = $this->limitFor($userId);

        $used = AiUsage::acrossTenants()
            ->when($userId === null, fn ($q) => $q->whereNull('user_id'))
            ->when($userId !== null, fn ($q) => $q->where('user_id', $userId))
            ->today()
            ->count();

        $remaining = max(0, $limit - $used);

        return [
            'limit' => $limit,
            'used' => $used,
            'remaining' => $remaining,
            'exhausted' => $remaining <= 0,
        ];
    }

    /**
     * May a call be made right now?
     */
    public function permits(?int $userId): bool
    {
        return ! $this->allowance($userId)['exhausted'];
    }

    /**
     * Record an attempt.
     *
     * Called for failures as well as successes, because OpenRouter bills per request that
     * reaches a model and a 5xx after generation is still a charge. Under-counting on the
     * days something is broken is the opposite of useful.
     *
     * @param  array<string, mixed>  $usage  The gateway's own usage block, if it sent one
     */
    public function record(
        ?int $userId,
        string $callSite,
        string $modelRequested,
        ?string $modelServed,
        bool $ok,
        ?string $failure = null,
        array $usage = [],
    ): AiUsage {
        return AiUsage::create([
            'user_id' => $userId,
            'call_site' => $callSite,
            'model_requested' => $modelRequested,
            'model_served' => $modelServed,
            'prompt_tokens' => $this->intOrNull($usage['prompt_tokens'] ?? null),
            'completion_tokens' => $this->intOrNull($usage['completion_tokens'] ?? null),
            'cost_usd' => $this->floatOrNull($usage['cost'] ?? null),
            'ok' => $ok,
            // Truncated rather than dropped: the column exists to group failures, and a
            // provider message long enough to overflow it is one nobody reads in full.
            'failure' => $failure === null ? null : mb_substr($failure, 0, 120),
        ]);
    }

    /**
     * Whose spend a call belongs to.
     *
     * `Tenant::current()` is set for dashboard requests and for the bot API. Console
     * commands set it per tenant as they iterate - see `ai:decide` and `telegram:review` -
     * so a scheduled call is attributed to the customer it was made for rather than to
     * nobody.
     */
    public function currentTenant(): ?int
    {
        return Tenant::current();
    }

    private function intOrNull(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private function floatOrNull(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }
}
