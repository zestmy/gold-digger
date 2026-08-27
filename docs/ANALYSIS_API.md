# Analysis API

For a client that is not this application's own Blade. Read
[`MARKET_SCAN.md`](MARKET_SCAN.md) for what the readings mean and
[`TENANCY.md`](TENANCY.md) for how the isolation works.

---

## Authentication

Bearer tokens from `bot_tokens` — the same credential the Expert Advisor uses, issued from
the terminal setup page and revocable there.

```
Authorization: Bearer gd_...
```

No second authentication system, deliberately. These tokens are already per-tenant, SHA-256
hashed and revocable, and `AuthenticateBot` names the tenant for the rest of the request —
so every model the controller touches filters itself without a single `where('user_id', …)`.
Adding Sanctum would have meant a second way to be authenticated and a second place for that
to be wrong, for no capability the existing one lacks.

> **One thing that caught me, and would catch anyone.** `SubstituteBindings` runs as part of
> the `api` group, *before* a route's own `bot.auth`. A route-model-bound `{analysis}` is
> therefore resolved before the tenant has been named, and the global scope has nothing to
> filter by — one account could read another's readings. The controller resolves the id
> itself, after auth. There is a test.

---

## Endpoints

All under `/api/v1/analysis`.

| | | |
|---|---|---|
| `GET` | `symbols` | What this account has bars for, and what a reading would cost |
| `GET` | `candles` | Raw bars, oldest-first, epoch seconds |
| `POST` | `quick` | The measured half — **free** |
| `POST` | `/` | The measured half plus a model reading — **metered** |
| `GET` | `{id}` | One stored reading |
| `POST` | `{id}/refresh` | Ask again, past the cache — **metered** |

### Two endpoints, because only one of them costs money

`quick` returns levels found by definition, structure, the timeframe ladder and the setup
candidates. It is arithmetic. It works with no API key, no credit and no network, and it
consumes nothing from the daily allowance — so a client can poll structure without buying a
paragraph every few seconds.

`POST /` is that plus one question put to a model, metered against
`bot_settings.ai_daily_call_limit` like every other call site.

Rate limits sit in front of both: 120/min for the measured endpoints, 10/min for the two
that spend. The real bound on spend is the daily allowance; the throttle is what makes a hot
loop meet a 429 rather than silently burning a day's calls in a minute.

---

## Shapes worth knowing before you build against them

**`setups` is often empty, and that is an answer.** `SetupClassifier` offers only patterns
whose own definition the conditions actually meet. An empty list means nothing here
qualifies — it is not padded to look like a result. Render it as "no setup", not as a
loading state.

**A `wait` reading has null prices.** `entry`, `stop_loss` and `take_profit` are null rather
than plausible numbers, and `complete` is `false`. Do not fill those in client-side; a
`wait` wearing prices reads as a trade nobody proposed.

**A failed reading still returns the measured half.** When the allowance is exhausted or the
provider is unreachable, `reading` is null, `levels` and `setups` are still populated, and
`error` says which happened — "allowance used up, it resets at midnight" and "the provider
is down" send somebody to different places, so they are not collapsed into one message.

**Candles are the broker's own series**, oldest-first, `timestamp` in epoch seconds. The
same bars the strategy trades from — a chart showing one series beside an analysis computed
on another is two halves of a page quietly disagreeing about which market they describe.

---

## What this does not do

**It places no orders.** This is the analysis stage of
`Market data → AI analysis → Signal → Risk engine → Execution`, and it stops at the second
arrow. Position sizing lives in `PositionSizer`, execution behind the copier's own gates,
and neither is reachable from here. That is the same boundary the dashboard keeps.

**It is not a streaming feed.** No WebSocket, no SSE, no push on a new bar. A client polls
`candles` and asks for a reading when it wants one. Re-analysing on every tick is explicitly
not the design — a reading is *of* a bar, and asking twice inside one bar returns the same
answer from cache rather than a differently-worded version of it.

**There is no OpenAPI document.** The endpoints are six, the shapes are above, and a
generated spec that drifts from the code is worse than a page that does not exist.
