# MT5 Trade Execution — Failure Analysis, Options, and Next Tasks

**Status:** analysis / proposal
**Context:** the Python bot connects to MetaTrader 5 but does not execute trades.

---

## 1. Where the project actually stands

Before diagnosing the bot, it is worth being precise about what exists in this repository, because it
changes what "fix the bot" means.

| Component | State in repo |
|---|---|
| Laravel dashboard (Livewire + Filament) | Built — pages for trades, strategies, broker accounts, analytics, settings, logs |
| MySQL schema | Built — `trades`, `trade_partials`, `signals`, `bot_logs`, `strategies`, `broker_accounts`, `bot_settings`, `daily_summaries` |
| Python trading bot | **Does not exist in the repo** — no `.py` files, no `requirements.txt` |
| API surface for the bot | **Does not exist** — `routes/` has no `api.php`; there are no controllers beyond auth |
| Bot control actions | Stubs — `QuickActionsCard::startBot()` / `stopBot()` / `closeAllPositions()` only flash "available in Phase 3" |
| Bot status | Hardcoded — `BotStatusCard::$isOnline = false`, never populated |

So the bot that "failed to execute" is running outside version control. That is itself the first
problem: there is no committed code, no log trail into `bot_logs`, and no way for anyone to reproduce
the failure. Every fix below assumes the bot moves into this repo.

The second structural issue: `DEPLOYMENT.md` provisions an **Ubuntu 24.04 DigitalOcean droplet**. The
`MetaTrader5` PyPI package is a thin IPC wrapper around a **running Windows MT5 terminal** — it has no
Linux build. If any part of the bot is being run on that droplet, no amount of code fixing will make
`order_send` work there. This is the single most likely explanation if the failure is "cannot connect
at all", and it is a hosting decision, not a bug.

---

## 2. Why `order_send` fails — ranked causes

Split the symptom into two classes, because the fixes are unrelated.

### Class A — `initialize()` / `login()` never really succeeds

| # | Cause | How to confirm | Fix |
|---|---|---|---|
| A1 | Running on Linux/macOS/Docker | `pip install MetaTrader5` fails, or `import` fails | Bot must run on Windows with the terminal, or switch to a non-terminal option (§4E/§4F) |
| A2 | MT5 terminal not running or not logged in | `mt5.initialize()` returns `True` but `mt5.account_info()` is `None` | Launch and log into the terminal first |
| A3 | Several MT5 installs; wrong one attached | `mt5.terminal_info().path` points at an unexpected folder | Pass `path=r"C:\Program Files\Elev8 MT5\terminal64.exe"` to `initialize()` |
| A4 | **Investor (read-only) password used** | Login succeeds, every order rejected | Use the master password |
| A5 | Terminal elevated, script not (or vice versa) | `initialize()` fails with `-10005 IPC timeout` | Run both at the same privilege level |
| A6 | Wrong server string | `-6 Terminal: Authorization failed` | Copy the server name verbatim from the terminal (e.g. `Elev8-Real`, not `Elev8-Real`) |

### Class B — connected, but `order_send` returns a non-`10009` retcode

This is the more likely class if you can read prices and account balance but orders bounce.

| Retcode | Constant | Real-world meaning for XAUUSD scalping |
|---|---|---|
| 10009 | `DONE` | Success |
| 10004 | `REQUOTE` | Price moved between quote and send. Gold moves fast — `deviation` of 0–5 points is too tight; use 20–30 and re-quote on retry |
| 10006 | `REJECT` | Broker-side rejection; check `result.comment` |
| 10013 | `INVALID` | Malformed request dict (missing `type_time`, wrong `action`) |
| 10014 | `INVALID_VOLUME` | Lot is below `volume_min`, above `volume_max`, or not a multiple of `volume_step`. Risk-sized lots like `0.037` fail here |
| 10015 | `INVALID_PRICE` | Stale price, or price not normalised to `symbol_info.digits` |
| 10016 | `INVALID_STOPS` | **SL/TP closer to price than `trade_stops_level`, or on the wrong side.** The most common scalping failure — see the pip trap below |
| 10018 | `MARKET_CLOSED` | Weekend, or gold's daily maintenance break |
| 10019 | `NO_MONEY` | Not enough free margin for the requested lot |
| 10026 | `SERVER_DISABLES_AT` | Algo trading disabled **server-side** for the account |
| 10027 | `CLIENT_DISABLES_AT` | **The "Algo Trading" button in the terminal is off.** Everything looks healthy, nothing executes |
| 10030 | `INVALID_FILL` | Filling mode not supported by this symbol/account |
| 10031 | `CONNECTION` | Terminal lost the broker connection |
| 10034 | `LIMIT_VOLUME` | Total exposure limit reached |

And the non-retcode traps:

- **`order_send()` returns `None`.** No retcode at all. Call `mt5.last_error()` — usually the IPC
  channel died, or the request dict has a bad key type (numpy floats are a frequent culprit; cast to
  native `float`).
- **Symbol not in Market Watch.** `mt5.symbol_info("XAUUSD")` returns `None` until you call
  `mt5.symbol_select("XAUUSD", True)`. A `None` here typically shows up downstream as an unhelpful
  crash rather than a retcode.
- **Broker symbol suffixes.** `.env.example` hardcodes `DEFAULT_SYMBOL=XAUUSD`. Real MT5 servers
  frequently expose gold as `XAUUSDm`, `XAUUSD.a`, `XAUUSD_i`, `XAUUSD.raw`, `XAUUSDc`, or `GOLD`.
  The `broker_accounts` table already supports Elev8, Exness, XM, IC Markets and Pepperstone — several
  of those use suffixes. Symbol names must be **resolved at runtime**, never hardcoded.
- **The pip trap (most likely cause of `10016`).** The `strategies` table stores targets in *pips*
  (`tp1_pips`, `tp2_pips`, `tp3_pips`, `sl_atr_multiplier`). On XAUUSD a broker `point` is `0.01`
  (2-digit) or `0.001` (3-digit), while traders colloquially call `0.10` "a pip". Treating a pip as a
  point makes a "10 pip" TP1 land 10 *points* away — well inside `trade_stops_level`, which on gold is
  routinely 20–50 points. Every order is then rejected with `10016`. Fix: convert pips → price via the
  symbol's `digits`/`point`, then clamp against `trade_stops_level` **and** `trade_freeze_level`.
- **Filling mode.** `symbol_info.filling_mode` is a bitmask (`1` = FOK allowed, `2` = IOC allowed).
  Market-execution accounts commonly reject `ORDER_FILLING_RETURN` and often reject `FOK` too. Hardcoding
  any single value produces `10030` on some brokers and works on others — which matches the widely
  reported pattern of identical code working at one broker and failing at another.
  ([MQL5 forum discussion](https://www.mql5.com/en/forum/482839))

**Recommended triage order:** run the preflight script (§6) → it reports which of these is true for
your account in about two seconds, instead of guessing.

---

## 3. The structural fix: separate "decide" from "execute"

The execution leg is the fragile part, and it is fragile for reasons outside your code (broker
config, terminal state, OS constraints). Design so that swapping it is a config change:

```
  Strategy engine            Laravel (source of truth)          Execution adapter
 ┌────────────────┐        ┌──────────────────────────┐       ┌────────────────────┐
 │ indicators     │  ───▶  │ signals                  │  ───▶ │ A. Python + MT5    │
 │ signal rules   │        │ trade_commands (queue)   │       │ B. MQL5 EA         │
 │ risk sizing    │  ◀───  │ trades / trade_partials  │  ◀─── │ C. ZeroMQ bridge   │
 └────────────────┘        │ bot_logs / heartbeats    │ fills │ D. File-drop       │
                           └──────────────────────────┘       │ E. Hosted MT5 API  │
                                                              │ H. MT5 under Wine  │
                                                              └────────────────────┘
```

Two concrete implications:

1. **Add a `trade_commands` table and a small API.** The dashboard's Start/Stop/Close-All buttons
   enqueue a command; the executor polls, acts, and reports back. That turns the three stubbed methods
   in `QuickActionsCard` into real features regardless of which executor you pick.
2. **Every executor writes to `bot_logs`.** The table and the `/logs` page already exist and are
   explicitly designed for cross-system logging. Right now they are empty, which is why the failure is
   invisible from the dashboard.

---

## 4. Practical execution options

| | Option | Runs on | Effort | Cost | Credentials leave your control |
|---|---|---|---|---|---|
| A | Python `MetaTrader5`, hardened | Windows VPS | Low | ~$10–20/mo VPS | No |
| B | MQL5 Expert Advisor + `WebRequest` | Windows VPS (inside terminal) | Medium | Same VPS | No |
| C | MQL5 EA + socket/ZeroMQ bridge to Python | Windows VPS | Medium-high | Same VPS | No |
| D | File-drop bridge (JSON in `MQL5/Files`) | Windows VPS | Very low | Same VPS | No |
| E | Hosted MT5 API (MetaApi, API2Trade) | Anywhere, incl. your Linux droplet | Low | Per-account subscription | **Yes** |
| F | Different broker with a native API (cTrader, OANDA, IBKR) | Anywhere | High (rewrite + move broker) | Varies | No |
| G | Signal-only / semi-automatic | Anywhere | Very low | None | No |
| H | Self-hosted MT5 in Docker under Wine | Linux, incl. your droplet | Medium | Same droplet, resized | No |

### A. Harden the Python + MT5 path *(do this first)*

Keep the architecture; make the executor defensive. Resolve the symbol at runtime, normalise volume to
`volume_step`, derive SL/TP from `point`/`digits` and clamp to `trade_stops_level`, autodetect the
filling mode from the bitmask, use `order_check()` before `order_send()`, retry on `10004`/`10015` with
a refreshed tick, and map every retcode to a `bot_logs` entry.

This is implemented in `bot/mt5_executor.py` in this branch. It removes causes B-10014, 10015, 10016,
10030 and most 10004 outright.

*Pick this if:* you can run a Windows VPS. It is the cheapest path and keeps credentials in-house.
*Blocker:* the `MetaTrader5` package is Windows-only, and the terminal must stay logged in.

### B. MQL5 Expert Advisor as the executor — **CHOSEN AND BUILT**

The EA runs **inside** the terminal, so the entire Python-IPC failure class (A1–A5, and `order_send`
returning `None`) disappears. The EA polls Laravel with `WebRequest`, executes, and posts fills back.

- Laravel exposes `GET /api/v1/commands` and `POST /api/v1/fills`, authenticated with a bearer token.
- In the terminal: Tools → Options → Expert Advisors → *Allow WebRequest for listed URL* → add your
  dashboard URL. HTTPS is required in practice.
- `OrderSend()` in MQL5 gets native access to `SYMBOL_TRADE_STOPS_LEVEL`, `SYMBOL_FILLING_MODE` and
  `SYMBOL_VOLUME_STEP`, and the `CTrade` standard-library class already handles filling-mode fallback.
- The strategy can stay in Python (it posts signals to Laravel) or move into MQL5.

*Pick this if:* you want the most reliable execution without paying a third party.
*Caveat:* `WebRequest` is synchronous and can stall the EA's tick handler — keep the timeout short
(1–2 s) and poll on a timer, not on every tick. Algo Trading must still be enabled.

**As built:** `mql5/Experts/GoldDigger/GoldDiggerBridge.mq5` plus `mql5/Include/GoldDigger/GDExecutor.mqh`,
against the `trade_commands` queue and the `/api/v1/bot/*` endpoints. Fill reports are buffered and
flushed on the timer rather than sent from `OnTradeTransaction`, precisely because of the caveat
above. Full detail in [`MT5_EA_BRIDGE.md`](MT5_EA_BRIDGE.md).

### C. EA + socket / ZeroMQ bridge

The EA holds a persistent socket; Python keeps all strategy logic and sends order instructions over it.
Lower latency than polling and the strategy stays in Python's ecosystem (pandas/numpy).

*Pick this if:* polling latency matters and you are comfortable running two processes.
*Caveat:* you now own connection lifecycle, reconnects, and message framing. More moving parts than B.

### D. File-drop bridge

Python writes `command.json` into the terminal's `MQL5/Files` sandbox; an EA on a timer reads,
executes, and writes `result.json` back.

*Pick this if:* you want something working in an afternoon, or as an emergency fallback when the API is
unreachable. Crude but genuinely hard to break.
*Caveat:* needs file locking to avoid partial reads; not suitable as the long-term primary path.

### E. Hosted MT5 API (MetaApi / API2Trade)

Third-party services run MT5 terminals in their cloud and expose REST + WebSocket. This is the only
option that lets the **existing DigitalOcean Linux droplet** place trades directly, with no Windows VPS
at all. MetaApi publishes a Python SDK (`metaapi-cloud-sdk`) that runs on Linux and has a free tier;
API2Trade positions itself as a lower-overhead alternative using protocol-level broker connections
rather than a hosted terminal per account.

*Pick this if:* you do not want to operate a Windows VPS, and you accept the trade-off.
*Caveat, and it is a real one:* you hand your **live broker credentials** to a third party, and add
their uptime and network hop to your latency budget. Test on a demo account first. Per-account pricing
also scales badly if this ever becomes multi-user — which `ARCHITECTURE.md` lists as a Phase 10 goal.

### F. Move to a broker with a first-class API

cTrader Open API, OANDA v20 REST, or Interactive Brokers all offer documented APIs with no terminal in
the loop, and run natively on Linux.

*Pick this if:* MT5 keeps fighting you and you are not tied to Elev8.
*Caveat:* it is a rewrite plus a broker migration, and gold spreads/commissions differ meaningfully
between venues — re-validate the strategy's edge before committing.

### G. Signal-only / semi-automatic *(ship this week, in parallel)*

Run the strategy, write to `signals`, surface them on the dashboard with a push/Telegram alert, and
place the trade by hand. Zero execution risk, and it validates the strategy's edge with real fills
while the execution work proceeds.

*Pick this if:* you want value out of the system now. It is a sequencing decision, not a compromise —
`signals.was_executed` and `skip_reason` already exist to support exactly this mode.

### H. Self-hosted MT5 on Linux, in Docker under Wine

The `MetaTrader5` package is Windows-only, but it is only an IPC client — it talks to a terminal on
the same machine. Run *both* under Wine in one container and the pair works on Linux: a Windows
Python process calling a Windows MT5 terminal, neither aware it is not on Windows. This is the only
row in the table that is Linux-native *and* keeps credentials in-house; E buys the first by giving up
the second.

The reference implementation is
[slowfound/metatrader5-quant-server-python](https://github.com/slowfound/metatrader5-quant-server-python),
from the *MT5 Quant Server* series ([part 1: MT5 on Linux](https://www.youtube.com/watch?v=0DU0fCwzVgw),
[part 2: the REST API](https://www.youtube.com/watch?v=SUzvM7g6Z6k)). The whole trick is one line in
its `07-start-wine-flask.sh`:

```bash
wine python /app/app.py
```

Around that it wraps the terminal in Flask — `health`, `symbol`, `data`, `position`, `order`,
`history` blueprints with Swagger docs — plus Traefik for HTTPS and VNC so you can drive the
terminal's first interactive login. Only its `backend/mt5` container is of interest here; its
Django/Celery half duplicates what Laravel already does. Wired in, it would sit behind the same
`/api/v1/bot/*` contract the EA uses, and `bot/mt5_executor.py` would stop being reference-only.

*Pick this if:* running a Windows VPS becomes untenable and handing a third party live credentials
is not acceptable.

*Caveats, and they are not small:*

- **Unsupported by MetaQuotes.** Wine + MT5 works until a terminal auto-update breaks it, and the
  terminal auto-updates. It will break on a day you did not choose.
- **Their `/order` handler is not hardened.** No runtime symbol resolution, no `volume_step`
  normalisation, no `trade_stops_level` clamping, no `order_check()` dry run — none of §2, in other
  words. `bot/mt5_executor.py` is what belongs behind that endpoint, not their version of it.
- **`sleep 5` then "is the PID alive" is not a health check.** A terminal that is running but logged
  out passes it. The preflight in §6 is the real readiness probe.
- **Resources.** `DEPLOYMENT.md` sizes the droplet at 2 GB / 1 vCPU for Laravel plus MySQL. A Wine
  MT5 terminal on top of that needs a resize first.
- **Blast radius.** Co-locating the terminal with the dashboard means one box failing takes out both
  the decider and the executor. A–D at least fail independently.
- **Unverified here.** Nothing in this repo has been run under Wine. This option is researched, not
  tested.

### Decision

**Option B.** Execution lives in an MQL5 EA; Laravel is the source of truth; the terminal polls
outward from a Windows VPS. This skips Option A entirely — hardening the Python path would have been
work thrown away once the EA existed, and the EA is immune to a failure class that Python only
mitigates.

Option A's artefacts are still worth keeping: `bot/mt5_preflight.py` diagnoses an account faster than
attaching an EA does, and `bot/mt5_executor.py` remains the reference the MQL5 executor mirrors.

Options E, F and H stay on the shelf. If a Windows VPS ever becomes untenable, reach for **H**
before E: both put execution on Linux, but H keeps the credentials in-house and costs nothing beyond
a droplet resize, where E costs a per-account subscription and your broker password. H's price is an
unsupported Wine stack that a terminal auto-update can break; take E only if H proves unstable in
practice.

---

## 5. Proposed task backlog

### Phase 2.0 — Diagnose *(this branch)*

- [x] Commit a preflight diagnostic that identifies the blocking cause
- [x] Commit a hardened order executor covering the known rejection causes
- [ ] Run `mt5_preflight.py` against the Elev8 account and record the actual retcode

### Phase 2.1 — Make the bot a first-class part of the repo ✓

- [x] `bot/` package with `requirements.txt` and the diagnostic/executor pair
- [x] `POST /api/v1/bot/logs` + a `BotLog` writer so failures appear on `/logs` instead of a console
- [x] `POST /api/v1/bot/heartbeat` + `bot_heartbeats` table, wired to `BotStatusCard` (replaces the
      hardcoded `$isOnline = false`)
- [x] Token auth for the API — `bot_tokens`, issued with `php artisan bot:token`

### Phase 2.2 — Command queue ✓

- [x] `trade_commands` migration with `type`, `payload`, `status`, `attempts`, `result`, `expires_at`
- [x] `GET /api/v1/bot/commands` (atomic claim) and `POST /api/v1/bot/commands/{id}/result`
- [x] The three `QuickActionsCard` stubs replaced with real command enqueues
- [x] Idempotency key per command so a retry cannot double-fill

### Phase 2.3 — Executor ✓ (partly)

- [x] Option B implemented: `mql5/` EA against the queue
- [x] Symbol resolution at runtime; the resolved name is reported on every heartbeat and shown
      on the dashboard
- [x] Pip↔point conversion in the executor, with an explicit `PipSize` input and a warning when inferred
- [x] Position sizing from `bot_settings.risk_percentage` — `PositionSizer`, from the stop
      distance and the `pip_value_per_lot` the terminal reports
- [x] Signal generation: `candles` + `StrategyEvaluator` + `SignalGenerator`. See
      `SIGNAL_GENERATION.md`

### Phase 2.3b - Trade management

- [x] Partial closes at TP1/TP2 per `tp1_close_pct` / `tp2_close_pct` - `TradeManager`.
      See `TRADE_MANAGEMENT.md`
- [x] `exit_on_reversal` - close when the EMAs cross back
- [x] `max_holding_bars` - forced exit after N bars
- [x] Move the stop to break-even after TP1, once the rung has actually filled
- [x] `modify` implemented in the EA - the type was in the command enum and the EA had been
      rejecting it as unknown
- [x] The wire's `reason` column is read by the EA, so a commanded close is recorded as the
      rung it was rather than as `manual`
- [x] Trailing stop - `trail_trigger_pips` / `trail_distance_pips`, off by default, following
      the best price seen and never loosening. Modelled identically in the backtester
- [x] Break-even offset, so the break-even stop clears the cost of the round trip
- [ ] News filter. `news_filter_enabled` is stored and unread; needs a news source, which
      means a third-party dependency and a judgement about which one
- [ ] Intrabar rung detection. A rung is noticed when its bar closes and filled at market, so
      a spike through TP1 that retraces fills worse than TP1. The exact fix is one position
      per rung with its own broker-side TP, at the cost of tripling position count

### Phase 2.4 - Reconciliation

- [x] Periodic sync of open MT5 positions into `trades` (magic number filter) -
      `PositionReconciler`, driven by `POST /api/v1/bot/positions`. See `RECONCILIATION.md`
- [x] Detect broker-side closes (SL/TP hit while the bot was down) and backfill
      `trade_partials` - the EA replays recent closing deals through `/fills` on attach,
      which is idempotent on the deal ticket
- [x] Sync balance/equity into `broker_accounts.last_balance` / `last_equity` /
      `last_synced_at` - written from every heartbeat
- [ ] Backfill the *entry* deal for an adopted position. The snapshot records what is open
      now; the deal that opened it, and its costs, are not recovered
- [ ] Let a user promote an adopted position to a managed one from the UI

### Phase 2.5 — Safety before any live capital

- [x] Kill switch honouring `bot_settings.is_active`, checked in the EA before every entry
- [x] Demo-only guard: the EA refuses to start on a live account unless `DemoOnly` is turned off
- [x] `max_daily_loss_percentage` and `max_concurrent_trades` enforced at signal generation,
      with the refusal recorded as a `skip_reason`
- [ ] The same two enforced in the *executor* as well — a command queued by hand still
      bypasses both, and the EA is the last line before the broker
- [ ] Alert on heartbeat loss (Telegram/email) — a silently dead bot with open positions is the real risk

### Phase 3 - Measurement

- [x] Backtester over stored candles, calling the same `StrategyEvaluator` the live path
      calls so results transfer. See `BACKTESTING.md`
- [x] Parameter sweeps and walk-forward validation - `backtest:optimise`, walk-forward by
      default and a sweep only on request. No verdict below 20 out-of-sample trades
- [ ] Persisted backtest runs, so two can be compared without re-running both

### Phase 5 - Scale

- [x] `symbol_specs`, so an instrument's figures live with the instrument rather than on the
      heartbeat. See `SIGNAL_GENERATION.md`
- [x] Multi-symbol, as one executor instance per symbol
- [x] Queued evaluation behind `QUEUE_STRATEGY_EVALUATION`, off by default, with a
      `queue_stalled` alert because a missing worker is otherwise silent
- [ ] Vendor history backfill, so a backtest can reach further back than the terminal can

### Deferred

- Screenshot capture (`trade_screenshots`), news filter, backtester, multi-account. All are already
  scoped in `ARCHITECTURE.md` and none should start before execution is reliable.

---

## 6. What ships in this branch

| File | Purpose |
|---|---|
| `docs/MT5_EXECUTION.md` | This document |
| `docs/MT5_EA_BRIDGE.md` | Setup, wire protocol, and troubleshooting for the chosen executor |
| `mql5/Experts/GoldDigger/GoldDiggerBridge.mq5` | The EA: polls, executes, reports fills and heartbeats |
| `mql5/Include/GoldDigger/GDExecutor.mqh` | MQL5 order primitives — the guards from §2, in the terminal |
| `bot/mt5_preflight.py` | Diagnostic walking every cause in §2; prints PASS/WARN/FAIL with the exact remedy |
| `bot/mt5_executor.py` | Python reference executor the MQL5 one mirrors |
| `app/Models/TradeCommand.php` etc. | Command queue, tokens, heartbeats |
| `routes/api.php` | `/api/v1/bot/*` — the contract between dashboard and executor |
| `tests/Feature/Bot/` | 41 tests covering the API, the dashboard controls, and the cross-language protocol contract |

---

## Sources

- [Order filling: Unsupported filling mode — MQL5 forum](https://www.mql5.com/en/forum/482839)
- [Python / MT5 — "Unsupported filling mode" — MQL5 forum](https://www.mql5.com/en/forum/368425)
- [MetaApi — cloud API for MetaTrader](https://metaapi.cloud/)
- [metaapi-cloud-sdk on PyPI](https://pypi.org/project/metaapi-cloud-sdk/)
- [MetaApi pricing comparison — API2Trade](https://www.api2trade.com/blog/metaapi-pricing-cost-comparison-mt4-mt5/)
