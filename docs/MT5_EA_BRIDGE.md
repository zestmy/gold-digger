# MT5 Expert Advisor Bridge

How the Gold Digger dashboard drives trade execution through an MQL5 Expert Advisor.

This is Option B from [`MT5_EXECUTION.md`](MT5_EXECUTION.md) §4, chosen over the Python
`MetaTrader5` package because the EA runs **inside** the terminal. The entire Class A
failure set from that document — Linux hosting, the terminal not being attached,
privilege mismatches, `order_send` returning `None` — cannot occur here.

---

## Architecture

```
  Browser                 Laravel + MySQL                  MetaTrader 5 (Windows VPS)
 ┌─────────┐            ┌──────────────────┐             ┌──────────────────────────┐
 │ Start   │──click───▶ │ trade_commands   │◀──GET ──────│ FXSignalPro.mq5          │
 │ Stop    │            │  (pending)       │   poll 5s   │  OnTimer                 │
 │ CloseAll│            │                  │──lines────▶ │  ├─ heartbeat            │
 └─────────┘            │ bot_heartbeats   │◀──POST──────│  ├─ flush fill reports   │
      ▲                 │ trades           │◀──POST──────│  ├─ claim + execute      │
      │                 │ trade_partials   │             │  └─ report results       │
      └──BotStatusCard──│ bot_logs         │◀──POST──────│  OnTradeTransaction      │
         (poll 10s)     └──────────────────┘             │   (broker-side SL/TP)    │
                                                         └──────────────────────────┘
```

The terminal sits on a VPS behind NAT: it can poll outward, but nothing can call in.
Everything is therefore pull-based, and every click becomes a queued row rather than a
synchronous call.

---

## Setup

### 1. Install the files

Copy into your terminal's data folder (MetaTrader → File → Open Data Folder):

| Repo path | Terminal path |
|---|---|
| `mql5/Include/FXSignalPro/` | `MQL5/Include/FXSignalPro/` |
| `mql5/Experts/FXSignalPro/` | `MQL5/Experts/FXSignalPro/` |

Open `FXSignalPro.mq5` in MetaEditor and compile (F7).

### 2. Whitelist the dashboard URL

Tools → Options → Expert Advisors → **Allow WebRequest for listed URL**, and add your
dashboard's origin exactly (scheme and host, no path):

```
https://fxsignal.pro
```

`WebRequest` fails with error `4014` until this is done. The EA detects that specific
error and prints the remedy rather than a generic failure.

Use HTTPS. The token is sent on every request; over plain HTTP it is readable in transit.

### 3. Issue a token

```bash
php artisan bot:token you@example.com --name="Windows VPS" --account=1
```

The plaintext is printed once and never stored — only its SHA-256 hash goes in the
database. Binding it to a broker account (`--account`) means a compromised demo terminal
cannot act on your live account.

### 4. Attach the EA

Drag it onto any chart. The chart's symbol and timeframe are irrelevant — every command
names its own symbol and all work happens on a timer.

Set the inputs:

| Input | Notes |
|---|---|
| `ApiBaseUrl` | Must match the whitelisted URL exactly |
| `ApiToken` | From step 3 |
| `BaseSymbol` | `XAUUSD`. Broker suffixes are resolved automatically |
| `PipSize` | **`0.10` for gold.** See the warning below |
| `MagicNumber` | Identifies this EA's positions; `close_all` only touches these |
| `Deviation` | `20`–`30` points. Gold moves fast; tighter values requote |
| `DemoOnly` | Defaults to **on**. Turn it off deliberately, not by accident |
| `DryRun` | Logs commands and reports them as not-executed |

Enable Algo Trading (toolbar button) **and** tick "Allow Algo Trading" in the EA's own
properties. They are different switches and both are required.

### 5. Confirm

The dashboard's Bot Status card should show **ONLINE** within a few seconds. If it shows
**BLOCKED**, the card names the reason — that state means the terminal is reachable but
cannot trade, which is otherwise indistinguishable from "the bot just never trades".

---

## The pip trap

Gold quotes with 2 digits, so the broker's `point` is `0.01` — but most gold strategies
call `0.10` a pip. If `strategies.tp1_pips` means the trader's pip and the EA assumes the
broker's point, every stop lands inside `trade_stops_level` and every order is rejected
with `10016`.

**Set `PipSize` explicitly.** When left at `0` the EA infers a value and prints a warning
saying it did.

---

## Wire protocol

The EA requests `Accept: text/plain` from `GET /api/v1/bot/commands` and receives a
version header followed by one tab-separated line per command:

```
GDCMD1
17\topen\tXAUUSD\tbuy\t0.05\t30\t15\t\t\t\tentry signal\t
18\tclose\t\t\t0.05\t\t\t\t\t987654\t\ttp1
```

Columns, in order — see `TradeCommand::WIRE_COLUMNS`:

```
id  type  symbol  direction  volume  sl_pips  tp_pips  sl_price  tp_price  ticket  comment  reason
```

**Why not JSON?** MQL5 ships no JSON parser, and an Expert Advisor executing real orders
is a poor place to debug a hand-rolled one. A fixed-column line is parsed with a single
`StringSplit()` call. Absent values are empty columns, never omitted, so the count is
constant; tabs and newlines are stripped from free text so a stray character in a comment
cannot shift every later column.

The same endpoint still returns JSON to any other client — only the EA asks for
`text/plain`.

Everything the EA *sends* is JSON, because building a string is safe where parsing one is
not.

`WireProtocolContractTest` reads the EA source and asserts `FXS_WIRE_VERSION` and
`FXS_WIRE_COLUMNS` still match the Laravel constants. Adding a column without bumping the
EA is otherwise a silent 3am failure.

---

## Endpoints

All under `/api/v1/bot`, all requiring `Authorization: Bearer <token>`.

| Method | Path | Purpose |
|---|---|---|
| `GET` | `/commands` | Claim the next batch. Atomically marks them `claimed` |
| `POST` | `/commands/{id}/result` | Report retcode, ticket, fill price, or the failure |
| `POST` | `/fills` | Record an open, partial close, or full close |
| `POST` | `/heartbeat` | Liveness, account snapshot and symbol specification; returns the kill-switch state |
| `POST` | `/logs` | Write into `bot_logs`, shown on `/logs` |
| `POST` | `/candles` | Push closed bars. A genuinely new bar triggers signal generation |
| `POST` | `/positions` | Full snapshot of open positions, so `trades` can be corrected |

### The kill switch

`POST /heartbeat` returns `trading_enabled`, read from `bot_settings.is_active`. The EA
refuses to open positions when it is false — checked at execution time, not only when the
command was queued, so an entry that was correct a minute ago does not fire after you
have stopped the bot.

Stopping flips the flag *before* queueing the `stop` command. A kill switch that depends
on a queued message being delivered is not a kill switch.

### Symbol specification

The heartbeat also carries `pip_size`, `digits` and `pip_value_per_lot`, for the same
reason it carries `resolved_symbol`: only the terminal knows them, and the dashboard must
not guess.

- **`pip_size`** turns the strategy's pip-based targets into the price levels stored on a
  signal.
- **`volume_min`** and **`volume_step`** are what let trade management decide whether a
  position can be divided into a ladder at all. Half of a broker's minimum lot is not a
  tradeable volume, and without these a 0.01-lot position would generate a failing partial
  close at every rung of every trade.
- **`pip_value_per_lot`** is the whole of position sizing — `SYMBOL_TRADE_TICK_VALUE *
  (pip_size / SYMBOL_TRADE_TICK_SIZE)`, which depends on contract size and the deposit
  currency.

Either sent as `null` makes the dashboard record signals unexecuted (`no_symbol_spec` /
`lot_size_unavailable`) rather than size a position from a hardcoded gold multiplier. A
wrong value here does not fail loudly — it trades a size nobody chose.

### More than one instrument

Run **one EA instance per symbol**, each with its own token and its own `BaseSymbol`. Each
pushes its own bars and reports that instrument's specification alongside them, which the
dashboard stores in `symbol_specs`.

Deliberately one instance per symbol rather than one EA looping over several: the instances are
isolated from each other, and it needs no refactor of code that has never been through a
compiler.

### Candles

The EA pushes closed bars for the entry and trend timeframes so the dashboard can compute
indicators and decide entries. See [`SIGNAL_GENERATION.md`](SIGNAL_GENERATION.md) for what
happens to them, and [`TRADE_MANAGEMENT.md`](TRADE_MANAGEMENT.md) for the take-profit ladder
and exits the same bars drive.

| Input | Default | Meaning |
|---|---|---|
| `PushCandles` | `true` | Send closed bars at all |
| `EntryTimeframe` | `PERIOD_M5` | **Must match** `strategies.timeframe_entry` |
| `TrendTimeframe` | `PERIOD_H1` | **Must match** `strategies.timeframe_trend` |
| `HistoryBars` | `300` | Sent on the first push, for indicator warm-up |
| `WindowBars` | `5` | Re-sent on each new bar, so a dropped push self-heals |

Three things about this are deliberate:

- **Index 1, never index 0.** Only closed bars are sent. A forming bar's high, low and
  close all still move, so an EMA cross computed on one can appear and vanish inside the
  same bar — an entry the completed bar never justified.
- **Times are converted to UTC** with `TimeGMT()` before sending. `iTime()` returns
  broker-server time, usually UTC+2 or UTC+3; unconverted, every bar would be filed under
  an hour it did not happen in and the session filter would gate London against the wrong
  window.
- **The push happens above the Algo Trading check**, so a terminal with the button off
  keeps the series current and keeps recording what the strategy would have done. The
  dashboard sees the same flag on the heartbeat and skips those signals with
  `algo_trading_disabled` instead of queueing entries that could only be refused.

If the timeframes here disagree with the strategy's, the dashboard stores the bars and
generates nothing — it only evaluates strategies whose *entry* timeframe just closed a bar.

### Reconciliation

| Input | Default | Meaning |
|---|---|---|
| `Reconcile` | `true` | Send snapshots of open positions |
| `ReconcileMinutes` | `15` | Minutes between snapshots |
| `ReplayHistoryDays` | `3` | How far back to re-report closes when the EA attaches |

On attach the EA replays recent closing deals through `/fills` and then sends a snapshot.
Only positions carrying `MagicNumber` are reported, and that scope is what lets a missing
position be treated as closed. See [`RECONCILIATION.md`](RECONCILIATION.md).

---

## What the EA guards against

Mirrors `bot/mt5_executor.py` deliberately, so both executors send the same request:

| Guard | Rejection avoided |
|---|---|
| Symbol resolved by scanning the server's list | silent failure on `XAUUSDm` / `.a` / `GOLD` |
| Volume snapped **down** onto `volume_step` | `10014` — and never rounds risk up |
| SL/TP clamped outside stops **and** freeze level | `10016` |
| Filling mode from `SYMBOL_FILLING_MODE`, with fallback | `10030` |
| Retry with a fresh tick on requote | `10004`, `10020`, `10021` |
| Position ticket read from the deal's `DEAL_POSITION_ID` | closes aimed at the wrong ticket in netting mode |

Every retcode is mapped to a plain-language remedy by `FXSExplainRetcode()` and lands in
`bot_logs`.

---

## Reporting closes the EA did not ask for

`OnTradeTransaction` catches deals the dashboard never requested — a stop loss or take
profit hit at the broker while nothing was polling. Without it, positions that closed
hours ago would still show as open.

Two details worth knowing:

- **Reports are queued, not sent inline.** `WebRequest` is synchronous; calling it from a
  trade-transaction handler would stall the terminal's event thread on every fill. Reports
  are buffered and flushed on the next timer tick, and again on `OnDeinit`.
- **Pips are computed in the terminal.** Only it knows the symbol's point size. Deriving
  them dashboard-side would mean guessing the multiplier that causes the whole `10016`
  class of bugs.

`trade_partials.close_reason` is a fixed enum that cannot express which ladder step a
broker-side TP fill was, so the precise MT5 reason travels alongside it as `closure_note`
and is stored in `trades.closure_reason`.

---

## Troubleshooting

| Symptom | Cause |
|---|---|
| `WebRequest ... error 4014` | URL not whitelisted, or whitelisted with a trailing path |
| Bot Status shows **BLOCKED** | Algo Trading off, or the terminal lost the broker connection. The card says which |
| `HTTP 401` in the Experts log | Token wrong, revoked, or expired. Issue a new one |
| `Wire protocol mismatch` | EA compiled from a different commit than the dashboard is running |
| Commands claimed but nothing happens | Check `DryRun`. It reports every command as not-executed |
| `10016` on every order | The pip trap. Set `PipSize` to `0.10` |
| Nothing in `/logs` | The EA logs failures there; silence means it is not reaching the API at all |

The Experts tab in the terminal carries the same messages with more detail.

---

## Limitations

- **The EA executes; it does not decide.** Entries are decided dashboard-side from the
  bars the EA pushes — see [`SIGNAL_GENERATION.md`](SIGNAL_GENERATION.md).
- **Ladder rungs are filled at market, one bar late.** The order carries the *final*
  target; TP1 and TP2 are noticed when the bar that touched them closes, then closed at
  market. A spike through a rung that retraces inside the same bar fills worse than the
  rung. See [`TRADE_MANAGEMENT.md`](TRADE_MANAGEMENT.md).
- **Reconciliation only sees this EA's magic number.** Positions opened by hand, or by
  another EA, carry a different magic and are deliberately not reported. See
  [`RECONCILIATION.md`](RECONCILIATION.md).
- **`max_concurrent_trades` and `max_daily_loss_percentage` are enforced when a signal is
  generated, not in the EA.** A command queued by hand still bypasses both.
- **The EA cannot be compiled or tested in CI.** It needs MetaEditor and a Windows
  terminal. `WireProtocolContractTest` pins the constants both sides share; everything
  else has to be verified on a demo account.
