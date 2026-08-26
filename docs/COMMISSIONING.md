# Commissioning

Getting the Expert Advisor from never-compiled to demonstrably working, once.

This is the step nothing else can substitute for. Every Laravel-side behaviour in this repo is
covered by tests; the terminal half is a hypothesis until an EA has been compiled, attached,
and watched executing a command against a broker.

You need a Windows machine with MetaTrader 5 installed. There is no way around that —
MetaEditor is Windows-only.

> **Done once, on 2026-08-25**, on demo account `230070844 @ Elev8-Demo2` against
> `https://fxsignal.pro`. The procedure below is what was run; the notes marked
> **Measured** are what that account actually reported, and **Found** marks something that
> only went wrong because it was run for real. Repeat this whole sequence for any new
> account, broker or machine — none of the measured values transfer.

---

## Before you start

| | |
|---|---|
| Account | A **demo** account. `DemoOnly` defaults to on and the EA refuses to start on a live one. |
| Dashboard | Reachable over **HTTPS** from the Windows machine. Check in a browser first. |
| Token | `php artisan bot:token you@example.com --name="Windows VPS" --account=1` — shown once, unrecoverable. |
| Strategy | One, with `is_active` set. Nothing generates without it. |

---

## 1 · Compile

Copy `mql5/Include/FXSignalPro/` and `mql5/Experts/FXSignalPro/` into the terminal's data folder
(File → Open Data Folder), then open `FXSignalPro.mq5` in MetaEditor and press **F7**.

Fix what it reports before going further. A warning about an unused variable can wait; anything
about a type or a signature cannot.

> **Measured.** First compile: `0 errors, 0 warnings`. The static checks that stood in for a
> compiler — brace balance, definition-before-use, `StringFormat` arity — turned out to have
> been enough. Do not read that as a reason to skip this step; it is the reason to keep those
> checks.

MetaEditor also compiles headlessly, which is easier to automate and easier to trust:

```bash
MetaEditor64.exe /compile:"...\Experts\FXSignalPro\FXSignalPro.mq5" /log:"...\gd.log"
```

The log is UTF-16 and the exit code means nothing useful — read `Result: N errors, M warnings`.

> **Found.** An external compile writes a new `.ex5` but does **not** reload an EA the terminal
> is already running, and nothing in the UI says so. Remove the EA from the chart and re-attach
> it, then confirm in the Journal that `loaded successfully` carries a timestamp later than the
> `.ex5` file's. Two rounds of "the fix didn't work" were the old binary still running.

---

## 2 · Whitelist the dashboard

Tools → Options → Expert Advisors → **Allow WebRequest for listed URL**, and add the origin.

Scheme and host only — `https://fxsignal.pro`, no trailing path. A path there is the usual
cause of `WebRequest` error **4014**, which the EA logs with that exact explanation.

---

## 3 · Attach, on a demo chart

Any chart — the EA works off its own timer and ignores the chart's symbol and timeframe.

| Input | Set it to |
|---|---|
| `ApiBaseUrl`, `ApiToken` | Your dashboard and the token from above |
| `BaseSymbol` | `XAUUSD` — suffixes are resolved at runtime |
| `PipSize` | **`0.10`** for gold. Read the note below before changing it |
| `EntryTimeframe` / `TrendTimeframe` | **Must match the strategy's.** If they disagree, bars accumulate and nothing is ever generated |
| `DemoOnly` | Leave on |

Both **Algo Trading** switches must be on: the toolbar button is terminal-wide, and the EA's
own checkbox is separate.

> **Found.** MT5 turns Algo Trading off by itself when the account changes —
> `automated trading is disabled because the account has been changed` — so logging into the
> demo account *after* enabling it silently undoes the step you just took. The EA's own
> checkbox also resets every time it is re-attached. The heartbeat reports the two switches
> ANDed together, so the dashboard shows BLOCKED while the toolbar button is visibly on, and
> attention goes to the switch that is visible rather than the one that is not.

> **Found.** `DemoOnly` refused to start on a terminal that had switched to a live account
> mid-commissioning, logging `This is a LIVE account and DemoOnly is enabled. Refusing to
> start.` It was the first time that guard had ever run. Check which account the terminal is
> actually on before assuming an init failure is a bug — the Journal names it on every
> connect.

> **The pip trap.** Gold quotes with 2 digits so the broker's `point` is `0.01`, but most gold
> strategies mean `0.10` by "a pip". Wrong by 10×, every stop lands inside `trade_stops_level`
> and every order returns `10016`. Left at `0` the EA infers and warns; setting it explicitly
> is better.

---

## 4 · Confirm the round trip

In order. Each step depends on the one before it, so stop at the first that fails.

**Heartbeat.** The dashboard's Bot Status card should read ONLINE within a few seconds. If it
says BLOCKED, Algo Trading is off somewhere. If it never changes, check `/logs` — the EA
reports there before anything else works, so silence means it is not reaching the API at all.

**Symbol truth.** The card shows the resolved symbol (`XAUUSDm`, `XAUUSD.a`, …). That resolved
name is what the strategy must be configured with, not the generic one.

> **Measured**, on `Elev8-Demo2`. Run `bot/mt5_preflight.py` and it reports all of this before
> the EA is ever attached — it is much the faster way to learn an account.
>
> | | |
> |---|---|
> | Resolved symbol | `XAUUSD` — no suffix on this server, so the generic name happened to be right |
> | Digits / point | `2` / `0.01`, contract size `100` |
> | Pip size | `0.10` — ten points to a pip, so `PipSize` must be set explicitly |
> | Stops level | `40` points = `0.40` = **4.0 pips**; every SL/TP must clear it |
> | Filling mode | bitmask `1` → FOK, with RETURN as fallback |
> | Volume | min `0.01`, step `0.01` |
>
> With TP1 at 30 pips the ladder clears the stops level by a wide margin. On a broker with a
> wider stops level, or a strategy with tighter targets, that is the first thing to check —
> it is the `10016` class of failure, and it is a configuration error, not a bug.

**Bars.** The Signals page has a price-feed panel. Both timeframes should appear; the entry
series needs roughly 100 bars before indicators read at all, so it may say WARMING UP briefly
after the first push.

```sql
select timeframe, count(*), max(open_time) from candles group by timeframe;
```

**A harmless command.** Queue Close All from Quick Actions with nothing open. It should reach
`done` in `trade_commands` within a poll or two. This proves the queue round trip without
risking a position.

> **Found — and this is the one that justifies the whole step.** The first four attempts never
> completed. `bot_logs` filled with `Malformed command line: expected 12 columns, got 2` and
> the rows sat at `claimed` until they expired.
>
> `toWireLine()` renders a payloadless command as an id, a type, and ten empty columns — on
> the wire, ten trailing tabs. The EA called `StringTrimRight()` on each line before splitting
> it, and MQL5 counts a tab as whitespace, so all ten were eaten and a valid twelve-column
> line arrived as two. It broke every payloadless command: `close_all`, `start` and **`stop`**.
>
> The kill switch survived only because it flips `bot_settings.is_active` server-side *before*
> queueing rather than relying on the queued `stop` being delivered. That decision is recorded
> in `HANDOFF.md` as a design note; this is the day it paid.
>
> The cross-language contract test could not have caught it. It compares `FXS_WIRE_COLUMNS`
> against `count(WIRE_COLUMNS)`, and both sides genuinely agreed on twelve — the disagreement
> was about whitespace. Tests were added for the shape rather than the constant. **Use a
> command that carries no payload for this check**, precisely because it is the fragile shape;
> an `open` with every column populated would have sailed through and proved less.

> **Found.** A command that an executor claims but never reports on has no reaper.
> `commands:sweep` only expires rows still `pending`, on the reasoning that no executor ever
> held them. Three rows are stranded at `claimed` from the above and will stay that way. Not
> harmful — nothing re-serves them — but do not read `claimed` as "in flight".

**A signal.** Watch the Signals page. Early rows will mostly carry a `skip_reason` — that is
the system working, not failing. Each reason names the one gate that would have to change.

**A fill.** The first `open` command that reaches `done` should produce a row in `trades` and
flip its signal to Traded. Check `trade_partials.close_reason` when it eventually closes: a
rung recorded as `manual` means the reason did not survive the round trip.

---

## What to watch for

Ranked by how likely they are to be the thing that goes wrong.

- **Nothing is adopted by reconciliation.** The snapshot filters on `MagicNumber`, so positions
  opened by hand or by another EA are deliberately not reported. Intended, and the most likely
  surprise.
- **A flood of `closed` fills on attach.** Expected once. The EA replays `ReplayHistoryDays` of
  closing deals, and `/fills` ignores any it already has, keyed on the deal ticket.
- **`modify` rejected with 10016.** A broker's stops level is measured from the *current* price,
  so a break-even stop is refused exactly when price has come back near entry — which is when it
  is asked for. `ClampStops` handles it; this is the first place to look if the stop never moves.
- **Every signal skipped as `lot_size_unavailable`.** The symbol reports no tick value, so pip
  value per lot is unknown. The dashboard refuses to size rather than guess.

---

## Do not skip to live

`DemoOnly` exists because the direction of that mistake is expensive and the safe default costs
nothing. Before turning it off, at minimum:

- One full ladder observed end to end: entry, TP1 partial, break-even stop, final exit.
- `max_concurrent_trades` and `max_daily_loss_percentage` observed actually blocking a signal.
- The kill switch tested while a position is open — Stop should halt new entries and leave the
  position alone. Now that the wire fix has landed, test the *queued* `stop` too, not only the
  server-side flag: the flag is what saved this once, and it should not have to again.
- Alerting **delivering**, not merely working. ✅ Verified 2026-08-25 by detaching the EA and
  waiting for the message to arrive on a phone — incident and resolution both delivered,
  `notified_at` and `resolution_notified` set on the row.

The other three had not been met as of the 2026-08-25 commissioning. No position has ever
been opened by this system, so nothing about entry, the ladder, break-even, trailing or the
exits has been observed anywhere but in tests and backtests.

> **Found.** Alerting is the one to be careful about, because it fails quietly in the
> flattering direction. During commissioning the health checks opened and resolved four
> incidents, including a `critical` whose body named the exact misconfiguration that had the
> system stalled — and every row carried `notified_at: null, notify_count: 0`, because
> `TELEGRAM_BOT_TOKEN` was unset. The `alerts` table looked healthy the whole time. Check
> delivery by causing a real incident — detach the EA for two minutes — and confirm the
> message arrives on your phone. An alerting system verified by reading its own database is
> not verified.
