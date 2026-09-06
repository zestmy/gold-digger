# Gold Digger — architecture

What is actually running, as of the code in this tree. An earlier version of this file
described a Python bot writing straight to MySQL, a REST API that was "Phase 6" and a
ten-phase roadmap ending in SaaS. None of that survived contact with a real terminal —
`docs/HANDOFF.md` records why — and a design document that describes a system which does
not exist is worse than none, because it is believed.

This is one page on how the pieces fit. The per-topic detail lives in `docs/` and is
linked from each section rather than repeated here.

---

## The shape of it

```
                          ┌───────────────────┐
                          │  Browser          │  Livewire pages; Filament at /admin
                          └─────────┬─────────┘
                                    │ HTTPS, session
                                    ▼
┌────────────────────────────────────────────────────────────────────────┐
│  Laravel 12                                                            │
│                                                                        │
│   routes/web.php       the dashboard, /setup, /terminal, /admin        │
│   routes/api.php       /api/v1/bot   /api/v1/analysis   /api/v1/telegram│
│   routes/console.php   the scheduler - cron calls it every minute      │
│   app/Jobs             EvaluateNewBars, RunStrategyImprovement         │
│   app/Services         Strategy, Telegram, Ai, Monitoring, MarketData  │
│   app/Support/Tenancy  who a query belongs to                          │
└──────┬─────────────▲──────────────────▲──────────────────▲─────────────┘
       │             │ HTTPS             │ HTTPS             │ HTTPS
       │ MySQL       │ bearer token      │ bearer token      │ worker token
       ▼             │                   │                   │
┌────────────┐ ┌─────┴──────────┐ ┌──────┴───────────┐ ┌─────┴──────────────┐
│  MySQL     │ │ MetaTrader 5   │ │ telegram-collector│ │ telegram-worker    │
│  tables,   │ │ FXSignalPro EA │ │ (tenant-run,      │ │ (platform-run,     │
│  jobs,     │ │ mql5/          │ │  MTProto session  │ │  holds every hosted│
│  sessions  │ │ on a Windows   │ │  stays with them) │ │  session)          │
│            │ │ VPS            │ │  tools/           │ │  tools/            │
└────────────┘ └─────┬──────────┘ └──────────────────┘ └────────────────────┘
                     │
                     ▼
               the broker's MT5 server

  Outbound from Laravel, over HTTPS:
    OpenRouter          every model call, on one platform key   (app/Services/Ai)
    Telegram Bot API    alerts, and telegram:poll               (Monitoring, Telegram)
    Twelve Data         deep history on demand, never stored    (MarketData, optional)
    calendar and COT feeds                                      (app/Services/News)
```

Everything outside Laravel talks *inward*. The terminal sits on a VPS behind NAT, the
collector on whatever machine the tenant chose, the worker on the platform's own network;
none of them accept a connection, so all of them poll. A click on the dashboard therefore
becomes a row in `trade_commands` and waits to be claimed, rather than a synchronous call
to anything. That one fact shapes most of what follows.

---

## The pieces

### Dashboard — `routes/web.php`, `app/Livewire`

Livewire 3 pages behind Breeze authentication, with the auth pages themselves as Volt
components. Registration is off unless `REGISTRATION_ENABLED=true` — the route is not
defined at all when disabled, so `Route::has('register')` is the single source of truth
and the landing page hides its sign-up buttons rather than linking to a 403.

`/setup` is the four things that have to be true before a copied signal can become a
position, each read from the system on every render rather than remembered. `/terminal`
issues the EA's token and `/terminal/download` ships the EA source with this dashboard's
`APP_URL` written into its default input, because telling people to edit MQL5 before
their first compile is a step that gets skipped.

Filament 3 at `/admin` is the support console, gated by `users.is_admin`. It has no login
page of its own — an administrator signs in at `/login`, where the two-factor challenge
runs, and the panel accepts that session. It is the one place cross-tenant access happens
by design — see `docs/TENANCY.md` for what that costs and how it is recorded.

### Bot API — `routes/api.php`

Four groups, one authentication contract. Every route takes a bearer token from
`bot_tokens` (SHA-256 hashed, per device, optionally bound to one broker account), and
`AuthenticateBot` names the tenant for the rest of the request so every model filters
itself. Rate limits are keyed by token rather than IP, because several terminals share an
office address and one tenant must not be able to spend another's allowance.

| Prefix | Client | Doc |
|---|---|---|
| `/api/v1/bot` | The Expert Advisor: claim commands, report results and fills, heartbeat, push bars, snapshot positions, write logs | `docs/MT5_EA_BRIDGE.md` |
| `/api/v1/analysis` | Any client wanting the chart analysis without the Blade around it | `docs/ANALYSIS_API.md` |
| `/api/v1/telegram` | A tenant's self-hosted collector | `tools/telegram-collector/README.md` |
| `/api/v1/telegram/worker` | The platform's hosted session worker, on an infrastructure token rather than an issued one | `tools/telegram-worker/README.md` |

Commands reach the EA as tab-separated lines, not JSON, because MQL5 ships no JSON parser
and an EA executing real orders is a poor place to debug a hand-rolled one. Only the EA
asks for `text/plain`; every other client gets JSON. `WireProtocolContractTest` reads the
EA source and fails if the column list drifts from `TradeCommand::WIRE_COLUMNS`.

### Executor — `mql5/`

`FXSignalPro.mq5` and `Executor.mqh`, running inside the terminal. It polls for commands
on a timer, executes them, buffers fill reports and flushes them on the next tick, sends a
heartbeat carrying the account snapshot and the symbol spec, and pushes closed bars. It
catches broker-side SL/TP fills nothing asked for through `OnTradeTransaction`.

Running *inside* the terminal is the whole point. The Python `MetaTrader5` package is an
IPC wrapper around a running Windows terminal, so the failure class that used to dominate
— terminal not attached, privilege mismatch, `order_send` returning `None`, Linux hosting —
cannot occur. `bot/` survives as a diagnostic (`mt5_preflight.py`) and as the reference
the MQL5 executor mirrors (`mt5_executor.py`); it is not wired into the queue and does
not trade.

### Strategy layer — `app/Services/Strategy`, `app/Jobs`

A candle push is the trigger. When the EA posts a bar the dashboard has not seen,
`CandleController` runs `TradeManager` over open positions first (a reversal or timeout
exit should be queued ahead of the same bar's new entry) and then `SignalGenerator` over
every active strategy whose entry timeframe just closed. Entries are decided here, not in
the terminal: the EA pushes bars, the dashboard computes the indicators and queues the
order.

By default that happens inside the request. `QUEUE_STRATEGY_EVALUATION=true` hands the
same call sequence to `EvaluateNewBars` on the `strategy` queue instead — deliberately the
same sequence, so the switch changes *when* the work happens and never *what* it does.
The queue driver is `database` (`QUEUE_CONNECTION=database`; there is no Redis), and the
job is unique per account and timeframe so a burst of pushes collapses to one evaluation.
The cost is a dependency: without `php artisan queue:work --queue=strategy` bars are
stored and the bot silently stops trading, which is why `HealthMonitor` raises
`queue_stalled` and why the switch is off by default.

`RunStrategyImprovement` is the other job: a walk-forward over twenty thousand bars takes
minutes and cannot live inside a Livewire request.

Detail: `docs/SIGNAL_GENERATION.md`, `docs/TRADE_MANAGEMENT.md`, `docs/RECONCILIATION.md`,
`docs/BACKTESTING.md`, `docs/NEWS_FILTER.md`.

### Scheduler — `routes/console.php`

Requires cron calling `php artisan schedule:run` every minute; without it nothing here
runs. Everything scheduled is written to be a correction rather than a dependency — the
dashboard reads command expiry directly, so a server with no cron shows the right thing
and merely accumulates stale rows. The one exception is `news:fetch`: a news filter that
is switched on holds every entry as `news_data_stale` once the calendar is six hours old,
so a missing cron changes trading, in the safe direction.

| Command | Cadence | What |
|---|---|---|
| `bot:monitor` | every minute | Health checks and alerting |
| `trades:manage` | every minute | Re-runs the position-management pass over stored bars, so a push that stopped does not leave positions with only their broker-side stop; idempotent keys make it safe without a new bar |
| `commands:sweep` | every 5 minutes | Mark lapsed commands as expired |
| `news:fetch` | hourly | Economic calendar behind the blackout filter |
| `telegram:poll`, `telegram:review`, `telegram:execute`, `telegram:follow-up` | every minute | The copier, as four stages that fail independently |
| `copier:protect` | every minute | Trailing, break-even and profit-locking on copied positions |
| `ai:decide` | every 15 minutes | The system's own opinion, off unless `ai_autonomous` is set |
| `cot:fetch` | Saturdays | Commitments of Traders; context, never a gate |
| `data:prune` | Sundays 04:00 | Retention; the only scheduled command that deletes |

### Telegram signal copier — `app/Services/Telegram`, `tools/`

Three ways a message gets in, all landing in the same `SignalIngest::record()` so there is
only ever one idempotency check, one channel switch and one parse pipeline:

1. **Bot API polling** — `telegram:poll` reads `getUpdates` on `TELEGRAM_BOT_TOKEN`, from
   chats allow-listed in `config/telegram.php`. Only sees chats the bot was added to.
2. **Self-hosted collector** — `tools/telegram-collector`, signed in as the tenant's own
   account over MTProto, because providers do not add your bot to their channel. The
   `.session` file is a full account credential, so it stays on the tenant's machine and
   the dashboard only ever receives message text over a revocable bearer token.
3. **Hosted worker** — `tools/telegram-worker`, the same thing run by the platform so that
   adding an account is a browser flow. It trades the collector's safety property for
   onboarding a customer will finish; sessions are encrypted with `APP_KEY` and served
   only to `TELEGRAM_WORKER_TOKEN`. `TELEGRAM_HOSTED_BY_DEFAULT` picks which a new account
   gets.

From there: `SignalParser` reads the text (`ImageSignalReader` transcribes a screenshot),
`SignalReviewer` runs every deterministic gate and only then asks a model — which can
decline a signal the gates allowed and can never approve one they blocked —
`SignalExecutor` sizes it against the AI fund and queues the order, and
`PositionManager` and `FollowUpExecutor` manage what is open. Nothing in that chain can
widen a stop or exceed the fund.

### AI — `app/Services/Ai`

`OpenRouter` is the only class that talks to a model. Nine call sites hand it a prompt and
a JSON Schema and get back `{ok, data, error, model}`; it never throws. Everything is off
unless `OPENROUTER_API_KEY` is set, and the one rule shaping all of it is that **the model
never produces a number that becomes a price** — it picks levels by index, candidates by
number, actions from a closed enum. `AiSpend` meters calls per tenant per day
(`AI_DAILY_CALL_LIMIT`, overridable in `bot_settings`) and `AiFund` caps what the
autonomous paths may risk. `docs/AI_INTEGRATION.md` has the table of what each caller may
decide and how a wrong number is made impossible.

### Tenancy — `app/Support/Tenancy`

`Tenant` holds who the request belongs to, `TenantScope` adds `where user_id = <current>`
to every query on an owned model, and `BelongsToTenant` applies it — seventeen models
carry the trait. Outside a request the scope applies no filter rather than returning no
rows, because `bot:monitor` and `copier:protect` iterate every user by design and a scope
that silently returned nothing would be a bot that silently stops trading. `TenantSweep`
runs console work per tenant so one account's failure cannot skip everybody behind it.
`docs/TENANCY.md` has the trade-offs and the account-security half (TOTP, sessions).

### Monitoring — `app/Services/Monitoring`

`HealthMonitor` opens and resolves incidents in `alerts` (executor missing or offline,
broker disconnected, Algo Trading off, feed stalled, daily loss limit, queue stalled,
books disagree). `AlertNotifier` sends them to Telegram — per tenant via
`users.telegram_chat_id`, else the platform chat — and falls back to email only when a
tenant has no channel at all, not when the channel failed. `ErrorReporter` turns an
uncaught throw into the same kind of incident. `docs/MONITORING.md`.

### Market data — `app/Services/MarketData`

`MarketData::forTrading()` always reads the bars the terminal pushed; there is no setting
that changes that, because an ATR from a vendor's gold series against a fill on the
broker's is a stop sized from prices the broker never quoted. `forAnalysis()` and
`forBacktest()` may use a vendor (`MARKETDATA_KEY`) for deep history that is fetched and
dropped, never stored. `docs/MARKET_DATA.md`.

---

## Data model — the parts worth explaining

### Why `trade_partials` is its own table

A strategy closes a position in stages — the default strategy takes 50% at TP1, 30% at
TP2 and the rest at TP3, but the percentages are columns on `strategies`, not a rule of
the system. Each stage is a separate broker deal with its own ticket, price, timestamp,
commission and swap. A row per deal means an unknown number of partials per trade (zero
to many), P&L per rung, and questions like "how often does TP2 fill after TP1" being a
query rather than a parse.

`FillController` writes them from the EA's fill reports, keyed on the deal ticket so a
retried report updates rather than duplicates. `TradeManager` is what queues the closes,
each with a fixed idempotency key (`close:{trade}:tp1`) so a double evaluation cannot
close a rung twice.

### Why costs are itemised on `trades`

`entry_spread_pips`, `entry_spread_money`, `commission_money` and `swap_money` are kept
separately from gross and net profit. Which cost hurts is a real question — spread during
news is a different problem from a swap on a position held over a rollover — and a single
net figure cannot answer it. It also gives `HealthMonitor` something to check:
`books_disagree` fires when the recorded profit does not match the deals behind it.

### Tables nothing writes

Two tables exist with a model and a migration, and no code path in `app/` that inserts a
row:

- **`daily_summaries`.** Intended as a pre-aggregated cache for the analytics page. The
  analytics page computes from `trades` on request instead, and nothing populates this
  table. It is listed among the tables `data:prune` never touches, which is true and
  currently vacuous.
- **`trade_screenshots`.** Intended for chart captures at entry and each exit.
  `bot_settings.capture_screenshots` is a toggle the settings form saves and nothing
  reads; no screenshot has ever been captured or stored.

The support-console screens that listed both empty tables have been removed. The models
and tables stay, marked `UNUSED` in their docblocks, because dropping them is a migration
decision to be taken on its own rather than a side effect of a documentation pass.

### Ownership

Seventeen models carry `user_id` and the tenant scope. `signals`, `trade_partials` and
`trade_screenshots` do not: they reach their owner through `strategy_id` or `trade_id`,
which is correct and invisible to a reader, and is why they are the models the support
console has to audit through their owning relation rather than directly. `admin_actions`
deliberately carries no scope at all — it exists to record cross-tenant access.

### Encrypted at rest

Broker `account_number`, the TOTP secret, and hosted Telegram sessions are all encrypted
with `APP_KEY`. Losing the key means every broker account is re-entered, every enrolled
user re-enrols, and every hosted Telegram account signs in again.

### Enum columns

`trades.status`, `trades.direction`, `trade_commands.type` and several others are MySQL
`enum` columns. That is a fact about the schema rather than a design principle: adding a
value is a migration (`000031_allow_pending_command_type` had to `->change()` the column
to admit one), which is the cost of type checking at the database rather than in code.

---

## What is built, and what is not

Derived from the code and from `docs/HANDOFF.md`, which has the history and the next
actions in order.

**Built and exercised against a live terminal** (commissioned on a demo account — the
round trip, heartbeat, candle push, logging, health checks and alert resolution):

- The command queue, the EA, and the wire protocol with its contract test.
- Signal generation with confluence scoring, position sizing, the take-profit ladder,
  break-even and trailing stops, reversal and time exits, and reconciliation of positions
  the dashboard did not open.
- The news blackout filter and the COT feed.
- The Telegram copier end to end: three ingestion routes, parse, AI review, execution,
  follow-ups and position protection, with per-channel performance tracking.
- The AI layer: chart and pair analysis, the market scan, the strategy proposer with
  walk-forward validation, autonomous decisions behind `ai_autonomous`, all metered.
- Backtesting and parameter optimisation over stored or vendor bars.
- Health monitoring with Telegram and email alerting, incident dedup and retention.
- Tenancy, TOTP two-factor, session listing and revocation, the admin audit log.
- Setup and terminal pages, the configured EA download, and the database backup command.

**Not built**, and not planned in this file:

- Intrabar exits. Ladder rungs are detected on bar close and filled at market.
- Promoting an adopted position to a managed one, or backfilling its entry deal.
- Screenshot capture and daily summaries — see above.
- WebSockets. The dashboard polls; the EA polls; nothing pushes to a browser.
- QR rendering for TOTP enrolment, and platform-wide enforcement of 2FA.
- Subscription or billing of any kind. Per-tenant limits exist (`ai_daily_call_limit`);
  charging for them does not.
- A Python executor. `bot/` is a diagnostic and a reference, and stays that way.

**Never verified**, as of the last update to `docs/HANDOFF.md`: no strategy-generated
position has run a full ladder against a broker, reconciliation has never adopted
anything, and `DemoOnly` has never been off. Check that document before assuming
otherwise.

---

## Where to read next

| Question | Document |
|---|---|
| How does the EA talk to the dashboard | `docs/MT5_EA_BRIDGE.md` |
| Getting a terminal from never-compiled to a verified round trip | `docs/COMMISSIONING.md` |
| Why orders get rejected, with the retcode reference | `docs/MT5_EXECUTION.md` |
| How an entry is decided, and every reason one is refused | `docs/SIGNAL_GENERATION.md` |
| The ladder, the exits, break-even and trailing | `docs/TRADE_MANAGEMENT.md` |
| Positions the dashboard did not open | `docs/RECONCILIATION.md` |
| What the models are allowed to decide | `docs/AI_INTEGRATION.md` |
| Ranking instruments, and the analysis API | `docs/MARKET_SCAN.md`, `docs/ANALYSIS_API.md` |
| Where bars come from | `docs/MARKET_DATA.md` |
| Measuring a strategy change | `docs/BACKTESTING.md` |
| Standing aside around news | `docs/NEWS_FILTER.md` |
| Health checks and alerting | `docs/MONITORING.md` |
| Isolation between tenants, and account security | `docs/TENANCY.md` |
| Servers, cron, the queue worker, retention | `DEPLOYMENT.md` |
| What happened, what is verified, what is next | `docs/HANDOFF.md` |
