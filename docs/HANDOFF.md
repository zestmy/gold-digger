# Handoff — MT5 Trade Execution

Written at the end of the session that built the MQL5 bridge, so the context would survive
it. Updated **2026-08-25**, when the bridge was commissioned against a real terminal for
the first time and several things in here stopped being true.

Read this first, then [`MT5_EA_BRIDGE.md`](MT5_EA_BRIDGE.md) for how the bridge works,
[`COMMISSIONING.md`](COMMISSIONING.md) for the procedure that was actually run, and
[`MT5_EXECUTION.md`](MT5_EXECUTION.md) for the retcode reference.

---

## The problem this work answers

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
- `FXSignalPro.mq5` — polls, executes, reports fills/heartbeats/logs.
  `OnTradeTransaction` catches broker-side SL/TP fills nothing asked for.
- `Executor.mqh` — runtime symbol resolution (`XAUUSD` → `XAUUSDm`/`.a`/`GOLD`), volume
  normalisation, stops clamping, filling-mode detection with fallback, requote retry,
  retcode → remedy mapping.

**Python** (`bot/`) — `mt5_preflight.py` (diagnostic) and `mt5_executor.py` (reference).

**Tests** — 81 in `tests/Feature/Bot/`, including a cross-language contract test that reads
the EA source and fails if `FXS_WIRE_VERSION` / `FXS_WIRE_COLUMNS` drift from the Laravel
constants. That drift is how this integration breaks silently at 3am. Commissioning proved
that a matching column *count* is not the same as a line the EA can parse, so that test now
also checks the shape of a payloadless command — see the section below.

---

## What is NOT built

> **Update.** Signal generation, position sizing, trade management, reconciliation,
> trailing stops, backtesting and alerting have since been built — see
> [`SIGNAL_GENERATION.md`](SIGNAL_GENERATION.md),
> [`TRADE_MANAGEMENT.md`](TRADE_MANAGEMENT.md),
> [`RECONCILIATION.md`](RECONCILIATION.md), [`BACKTESTING.md`](BACKTESTING.md) and
> [`MONITORING.md`](MONITORING.md). What remains is below.

- **Intrabar exits.** Ladder rungs are detected on bar close and filled at market, so a
  spike that retraces fills worse than the rung. Trailing stops themselves now exist
  (`strategies.trail_trigger_pips` / `trail_distance_pips`); it is the granularity of the
  detection that is still bar-bound. See `TRADE_MANAGEMENT.md`.
- **Promoting an adopted position.** Reconciliation records positions it finds on the
  terminal, but they are never managed by a strategy and there is no way to say otherwise.
- **The entry deal of an adopted position** is not backfilled, so its costs are unknown.

Full backlog: `MT5_EXECUTION.md` §5.

---

## Commissioned 2026-08-25 — what is now verified

Everything in this section used to read "never verified". It was carried out on demo
account `230070844 @ Elev8-Demo2` against the production dashboard at
`https://fxsignal.pro`. `COMMISSIONING.md` has the procedure and the account's measured
constraints; this is the outcome.

**The EA compiles.** `0 errors, 0 warnings`, first attempt, via
`MetaEditor64.exe /compile:... /log:...` — which works headlessly and does not need the
GUI. The static checks that stood in for a compiler turned out to have been sufficient.

**The round trip works.** A `close_all` reached `done` in one second with `retcode 0`.
Heartbeat, candle push, `bot_logs`, health checks and alert resolution were all exercised
by a live terminal, not a stub.

**One real bug surfaced, and only a live command could have surfaced it.** The EA rejected
every payloadless command — `close_all`, `start` and `stop` — as `expected 12 columns, got
2`. `toWireLine()` renders those as an id, a type and ten empty columns, which on the wire
is ten trailing tabs; the EA's parser trimmed them off before splitting. The kill switch
was among the casualties and survived only because it flips `bot_settings.is_active`
server-side *before* queueing, exactly as the table above says. Fixed in
`fix/wire-line-trailing-tabs`; the parser now pads a short line and refuses only an
over-long one, and logs the offending line either way.

**The `DemoOnly` guard works.** It refused to start when the terminal turned out to be
logged into a live account. First time it was ever tested, and it was not a drill.

**The test suite passes: 325.** The nine `ViteManifestNotFoundException` failures noted
here previously were the missing frontend build, and `npm run build` clears them as
predicted.

### Still not verified

- **No position has ever been opened.** The kill switch has stayed off throughout. Entry,
  the TP ladder, break-even, trailing and the exits have never run against a broker.
- **Reconciliation has never adopted anything**, because nothing has ever been open.
- **`DemoOnly` has never been off**, and should not be until a full ladder has been
  watched end to end. See the last section of `COMMISSIONING.md`.

---

## Picking it up locally

```bash
composer install
npm install
npm run build          # without this, Blade view tests fail on ViteManifestNotFoundException
php artisan migrate
php artisan test       # expect 325 passing
```

That branch has long since merged; this all lives on `main` now.

To recompile the EA without opening MetaEditor — useful, because an external compile is
also the one way to be sure which binary the terminal is running:

```bash
"C:\Program Files\<broker> MT5 Terminal\MetaEditor64.exe" \
  /compile:"%APPDATA%\MetaQuotes\Terminal\<id>\MQL5\Experts\FXSignalPro\FXSignalPro.mq5" \
  /log:"%TEMP%\gd_compile.log"
```

The log is UTF-16. Exit code is not a reliable success signal — read `Result: N errors`.

---

## Next actions, in order

Steps 1–6 of the original list are done — see `COMMISSIONING.md`, which now records the
outcome as well as the procedure. What is left:

1. **Watch `signals` accumulate.** The strategy is active and the kill switch is off, so
   every setup is recorded with `skip_reason = bot_inactive`. That is the intended way to
   read the strategy's behaviour without risking anything: `SIGNAL_GENERATION.md` lists
   what each reason means. A run of them that never says `bot_inactive` means a gate
   earlier in `firstObjection()` is stopping everything, and that is worth understanding
   before enabling.
2. **Configure alerting.** `TELEGRAM_BOT_TOKEN` / `TELEGRAM_CHAT_ID` on the server. During
   commissioning the health checks opened and resolved four incidents — including a
   `critical` naming the exact misconfiguration that had the system stalled — and every
   one carried `notified_at: null`. The diagnosis was right and nobody was told.
3. **Turn the kill switch on** (Quick Actions → Start) and let one position run the full
   ladder: entry, TP1 partial, break-even stop, final exit. This is the first time any of
   `TradeManager` will have executed against a broker. Expect to find something.
4. **Only then** consider `DemoOnly`. The bar for that is in `COMMISSIONING.md`, and
   nothing has cleared it yet.

---

## Gotchas that will cost you an afternoon

- **The pip trap.** Gold quotes with 2 digits so the broker's `point` is `0.01`, but most
  gold strategies call `0.10` a pip. If `strategies.tp1_pips` means the trader's pip and the
  code assumes the broker's point, every stop lands inside `trade_stops_level` and every
  order returns `10016`. **Set `PipSize` explicitly.** Left at `0` the EA infers and warns.
- **Two Algo Trading switches.** The toolbar button is terminal-wide; the EA's own "Allow
  Algo Trading" checkbox is separate. Both must be on. This cost three rounds during
  commissioning even with the alert naming it, because the heartbeat reports the *pair*
  ANDed together — so the dashboard says BLOCKED while the toolbar button is visibly on,
  and the eye goes to the switch it can see. **MT5 also switches Algo Trading off by
  itself whenever the account changes**, which is exactly when you are least expecting it:
  `automated trading is disabled because the account has been changed`.
- **Recompiling does not reload a running EA** unless the compile came from a MetaEditor
  the terminal launched. Compile externally and the terminal keeps running the old binary
  with no indication anything has changed. Remove the EA from the chart and re-attach —
  and check the Journal for `loaded successfully` with a timestamp later than the `.ex5`,
  because "I reloaded it" and "it reloaded" are different claims. The EA prints
  `EA <version> attached on <symbol>` on init; that line is the proof.
- **`WebRequest` error 4014** means the URL is not whitelisted, or was whitelisted with a
  trailing path.
- **Symbol names are not `XAUUSD` everywhere.** `.env.example` hardcodes it; real servers
  publish `XAUUSDm`, `XAUUSD.a`, `GOLD`. The EA resolves at runtime and reports the resolved
  name on every heartbeat — that resolved name is what the strategy must be configured with.
- **`bot_logs` is now written by the EA.** If `/logs` is silent, the EA is not reaching the
  API at all — that is the first thing to check, not the last.
