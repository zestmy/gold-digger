# Tenancy — how one deployment holds more than one trader

Written when this stopped being a single-operator bot and had to survive strangers signing
up. Read `AI_INTEGRATION.md` next for the other half of that question: what a stranger is
allowed to *spend*.

---

## The problem this answers

Isolation used to be 93 hand-written `where('user_id', Auth::id())` clauses. That is not a
mechanism, it is a convention, and the thing about a convention enforced by memory is that
nothing tells you the one time it was forgotten.

`/logs` was that one time. `bot_logs` had no owner column at all, so:

- every tenant read every other tenant's executor output — rejected orders, retcodes,
  symbols, the shape of somebody else's trading;
- `clearLog($id)` deleted any row whose id was posted to it, with no ownership check;
- `clearAllLogs()` truncated the table **for the entire platform**.

The page was written when there was one operator. It was correct then. Nothing flagged it
when tenancy arrived, and nothing would have flagged the next one either.

---

## What replaced it

Three small pieces, in `app/Support/Tenancy/` and `app/Models/`:

| Piece | Job |
|---|---|
| `Tenant` | Who the current request belongs to. |
| `TenantScope` | Adds `where user_id = <current>` to every query on an owned model. |
| `BelongsToTenant` | Applies the scope, stamps `user_id` on create, and provides the escape hatches. |

Fifteen models carry the trait. The hand-written `where()` clauses were **left in place** —
they are now redundant rather than load-bearing, and removing 93 of them in the same change
that introduced the mechanism would have meant trusting the new thing before it had run
anywhere.

### Where the tenant comes from, in order

1. An id set explicitly by `Tenant::actAs()`. `AuthenticateBot` does this from the bearer
   token, so a machine request is scoped to the account that issued the token.
   `BindWorkerAccount` does the same for the hosted Telegram path.
2. The authenticated web user. This covers dashboard requests and Livewire — including
   Livewire component tests, which never traverse HTTP middleware.
3. Nothing. Console commands and queued jobs start here.

### Why "nothing" means "no filter" rather than "no rows"

Because the alternative silently breaks the trading engine. `bot:monitor`,
`copier:protect` and `ai:decide` iterate every user by design, and the backtester reads
across accounts on purpose. A scope that returned nothing outside a request would turn all
of that into a system that quietly stops trading — the worst failure this application has,
and one no test would catch, because the rows would be absent rather than wrong.

The trade-off is stated plainly rather than hidden: **this mechanism protects the surfaces
where tenants meet each other's data — the dashboard and the API — and console code remains
responsible for its own scoping.** That is where the remaining risk lives.

### Statics outlive requests

`Tenant` holds a static. Under PHP-FPM the process ends and nothing notices; under Octane,
in a queue worker, or in a test suite running hundreds of requests in one process, the next
piece of work would inherit the last request's tenant.

So `AuthenticateBot::terminate()` and `BindWorkerAccount::terminate()` put it back down, and
`Tests\TestCase` clears it between cases. This is not theoretical — it was caught by
`TenantIsolationTest` failing exactly that way before the `terminate()` hooks existed.

---

## Reading across tenants, deliberately

Two escape hatches, both awkward to type on purpose:

```php
BotLog::acrossTenants()->where(...)          // a query
Tenant::acrossTenants(fn () => ...)          // a block
```

They are named for what they cost rather than what they do:
`Trade::acrossTenants()` reads as a decision in a diff, where
`withoutGlobalScope(TenantScope::class)` reads as boilerplate somebody copied.

`BotToken::resolve()` is the canonical legitimate use. Resolving a credential is what
establishes who the tenant *is*, so it cannot itself be filtered by one.

Running console work **as** a tenant is the other direction:

```php
Tenant::for($user, fn () => $reviewer->review($signal));
```

`ai:decide`, `telegram:review` and `telegram:follow-up` do this. It buys two things at
once: every model inside filters itself to that tenant, and the AI calls the work pays for
are attributed to their allowance rather than to nobody.

---

## Adding a model

If it has a `user_id`, give it `BelongsToTenant`. If you forget,
`tests/Feature/Tenancy/TenantIsolationTest.php` is meant to be where you find out — add the
model to the list in `test_every_owned_model_filters_to_the_current_tenant` and the failure
message names it.

Two things the trait will not do for you:

- **`upsert()` bypasses model events**, so `user_id` must be in the payload. `Candle` does
  this already.
- **Raw query-builder calls** (`DB::table(...)`) are not Eloquent and are not scoped.

---

## What is still not covered

Honest list, so nobody assumes more than is true:

- **Filament.** The nine admin resources scope by nothing and are gated only by
  `users.is_admin`. That is deliberate — it is a support console — but it is one boolean
  away from repeating the `/logs` failure at much greater scope. It has no audit trail and
  no impersonation record.
- **`signals`, `trade_partials`, `trade_screenshots` and `bot_logs`' siblings** reach their
  owner indirectly, through `strategy_id` or `trade_id`. That works and is invisible to a
  reader; it is not asserted anywhere as an invariant.
- **Console fan-out** remains the caller's responsibility, per the trade-off above.
- **Per-tenant alert routing** now exists (`users.telegram_chat_id`), but a tenant who
  configures neither Telegram nor a reachable mailbox still hears nothing. The incident is
  recorded on `/logs` either way.
