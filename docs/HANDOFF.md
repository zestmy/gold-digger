# Handoff — MT5 Trade Execution

Written at the end of the remote session that produced branch
`claude/mt5-python-trade-execution-9pibf6` (PR #1), so the context survives the session.

Read this first, then [`MT5_EA_BRIDGE.md`](MT5_EA_BRIDGE.md) for how the bridge works and
[`MT5_EXECUTION.md`](MT5_EXECUTION.md) for the retcode reference.

---

## The problem this branch answers

"Connection to MT5 via the Python bot failed to execute trade."

Two findings shaped everything that followed:

1. **The Python bot was never in the repo.** No `.py` files, no API, and nothing had ever
   written to `bot_logs` — so a failure on the VPS was invisible from the dashboard.
2. **`MetaTrader5` on PyPI is a Windows-only IPC wrapper around a running MT5 terminal.**
   `DEPLOYMENT.md` provisions an Ubuntu droplet. If any part of the bot ran there, no code
   change would ever make `order_send` work. That is a hosting fact, not a bug.

---

## What was decided, and why

**Execution runs through an MQL5 Expert Advisor** (Option B of `MT5_EXECUTION.md` §4). The
EA runs *inside* the terminal, so the whole Python-IPC failure class — terminal not
attached, privilege mismatch, `order_send` returning `None`, Linux hosting — cannot occur.

**Option A (hardening the Python path) was deliberately skipped**, not deferred. It would
have been thrown away once the EA existed. `bot/` survives for two reasons: the preflight
script diagnoses an account faster than attaching an EA does, and `mt5_executor.py` is the
reference the MQL5 executor mirrors — when the two disagree about how to build a request,
that is a bug.

**Options E/F stay on the shelf.** Revisit a hosted MT5 API (MetaApi/API2Trade) only if
running a Windows VPS becomes untenable, and accept that it means handing a third party
live broker credentials.

### Smaller decisions worth not re-litigating

| Decision | Reason |
|---|---|
| Commands reach the EA as **tab-separated lines**, not JSON | MQL5 ships no JSON parser, and an EA executing real orders is a bad place to debug a hand-rolled one. The endpoint still serves JSON to every other client; only the EA asks for `text/plain`. |
| Fill reports are **buffered and flushed on the timer**, not sent from `OnTradeTransaction` | `WebRequest` is synchronous; calling it in a trade-transaction handler stalls the terminal's event thread on every fill. |
| **Pips are computed in the terminal**, never dashboard-side | Only the terminal knows the symbol's point size. Deriving them server-side means guessing the multiplier that causes the whole `10016` class of bugs. |
| Stop **flips `bot_settings.is_active` before** queueing the `stop` command | A kill switch that depends on a queued message being delivered is not a kill switch. The EA also re-checks the flag at execution time. |
| `trades.tp1_price` / `tp2_price` made **nullable** | They were `NOT NULL`. A position may legitimately run on a trailing stop; inventing target prices to satisfy the constraint would chart fictional levels as if they were real. |
| Volume is snapped **down** onto `volume_step` | Rounding up silently takes more risk than the setting allows. |
| `BotStatusCard` distinguishes **BLOCKED from OFFLINE** | A terminal with Algo Trading off keeps heartbeating. As a plain ONLINE badge, that failure reads as "the bot just never trades". |

---

## What is built

**Laravel**
- `trade_commands` — the queue. Atomic claim, unique idempotency key (a double-clicked
  button cannot open two positions), `expires_at` (a market order that waited out its
  window is never filled late).
- `bot_tokens` — per-device bearer credentials, SHA-256 hashed, optionally bound to one
  broker account. Issued with `php artisan bot:token`.
- `bot_heartbeats` — one upserted row per executor, drives `BotStatusCard`.
- `routes/api.php` → `/api/v1/bot/{commands,fills,heartbeat,logs}`, all token-authenticated.
- `QuickActionsCard` start/stop/close-all now queue real commands (were "Phase 3" stubs).

**MQL5** (`mql5/`)
- `GoldDiggerBridge.mq5` — polls, executes, reports fills/heartbeats/logs.
  `OnTradeTransaction` catches broker-side SL/TP fills nothing asked for.
- `GDExecutor.mqh` — runtime symbol resolution (`XAUUSD` → `XAUUSDm`/`.a`/`GOLD`), volume
  normalisation, stops clamping, filling-mode detection with fallback, requote retry,
  retcode → remedy mapping.

**Python** (`bot/`) — `mt5_preflight.py` (diagnostic) and `mt5_executor.py` (reference).

**Tests** — 41 in `tests/Feature/Bot/`, including a cross-language contract test that reads
the EA source and fails if `GD_WIRE_VERSION` / `GD_WIRE_COLUMNS` drift from the Laravel
constants. That drift is how this integration breaks silently at 3am.

---

## What is NOT built

> **Update.** Signal generation, position sizing, trade management and reconciliation
> have since been built — see [`SIGNAL_GENERATION.md`](SIGNAL_GENERATION.md),
> [`TRADE_MANAGEMENT.md`](TRADE_MANAGEMENT.md) and
> [`RECONCILIATION.md`](RECONCILIATION.md). What remains is below.

- **Trailing stops and intrabar exits.** The stop moves once, to break-even; nothing
  trails it. Ladder rungs are detected on bar close and filled at market, so a spike that
  retraces fills worse than the rung. See `TRADE_MANAGEMENT.md`.
- **Promoting an adopted position.** Reconciliation records positions it finds on the
  terminal, but they are never managed by a strategy and there is no way to say otherwise.
- **The entry deal of an adopted position** is not backfilled, so its costs are unknown.

Full backlog: `MT5_EXECUTION.md` §5.

---

## What was NOT verified — read before trusting this code

**The EA has never been compiled.** MetaEditor and a Windows terminal do not exist in a
Linux container. What was done instead: brace/paren balance with a proper tokenizer, every
helper confirmed defined, every `CGDExecutor` method the EA calls confirmed present on the
class. That is not a compiler. **Expect to fix at least a warning or two on first compile,
and treat compiling as a required step before this touches an account.**

**Nothing has been run against a real broker.** Every Laravel-side behaviour is covered by
tests; the Python executor was exercised against a stub terminal modelling an Octa-like
server. No real fill has ever happened.

**Nine tests fail in this container, on this branch and on untouched `main` alike**
(`9 failed / 17 passed` at baseline, `9 failed / 58 passed` with this work). They render
Blade views and hit `ViteManifestNotFoundException` because frontend assets were never
built. `npm run build` should clear them locally. Unrelated to this branch.

---

## Picking it up locally

```bash
git fetch origin
git checkout claude/mt5-python-trade-execution-9pibf6

composer install
npm install
npm run build          # also clears the 9 Vite failures above
php artisan migrate    # bot_tokens, trade_commands, bot_heartbeats, tp-nullable
php artisan test       # expect 67 passing once assets are built
```

Two commits sit on top of `main` at `d0f4fe5`:

- `e7bf583` — analysis, preflight diagnostic, Python reference executor
- `5d07580` — MQL5 EA bridge, command queue, bot API, dashboard wiring, tests

---

## Next actions, in order

1. **Compile** `GoldDiggerBridge.mq5` in MetaEditor (F7). Copy `mql5/Include/GoldDigger/`
   and `mql5/Experts/GoldDigger/` into the terminal's data folder first
   (File → Open Data Folder). Fix whatever the compiler says.
2. **Whitelist** the dashboard origin: Tools → Options → Expert Advisors → *Allow WebRequest
   for listed URL*. Scheme and host only, no path. HTTPS.
3. **Issue a token**: `php artisan bot:token you@example.com --name="Windows VPS" --account=1`.
   Plaintext is shown once and is unrecoverable.
4. **Attach to a chart on a DEMO account.** `DemoOnly` defaults to on and the EA refuses to
   start on a live account until it is turned off. Set `PipSize` to `0.10` for gold.
5. **Confirm** Bot Status shows ONLINE, then queue a Close All (harmless with no positions)
   and check it reaches `done` in `trade_commands`.
6. **Confirm bars are arriving**: `select timeframe, count(*), max(open_time) from candles
   group by timeframe`. The EA's `EntryTimeframe`/`TrendTimeframe` must match the
   strategy's, or bars pile up and nothing is ever generated.
7. Activate the strategy (`strategies.is_active`) and watch `signals`. Early rows will
   mostly carry a `skip_reason` — that is the point; `SIGNAL_GENERATION.md` lists what
   each one means.

---

## Gotchas that will cost you an afternoon

- **The pip trap.** Gold quotes with 2 digits so the broker's `point` is `0.01`, but most
  gold strategies call `0.10` a pip. If `strategies.tp1_pips` means the trader's pip and the
  code assumes the broker's point, every stop lands inside `trade_stops_level` and every
  order returns `10016`. **Set `PipSize` explicitly.** Left at `0` the EA infers and warns.
- **Two Algo Trading switches.** The toolbar button is terminal-wide; the EA's own "Allow
  Algo Trading" checkbox is separate. Both must be on.
- **`WebRequest` error 4014** means the URL is not whitelisted, or was whitelisted with a
  trailing path.
- **Symbol names are not `XAUUSD` everywhere.** `.env.example` hardcodes it; real servers
  publish `XAUUSDm`, `XAUUSD.a`, `GOLD`. The EA resolves at runtime and reports the resolved
  name on every heartbeat — that resolved name is what the strategy must be configured with.
- **`bot_logs` is now written by the EA.** If `/logs` is silent, the EA is not reaching the
  API at all — that is the first thing to check, not the last.
