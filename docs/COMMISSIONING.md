# Commissioning

Getting the Expert Advisor from never-compiled to demonstrably working, once.

This is the step nothing else can substitute for. Every Laravel-side behaviour in this repo is
covered by tests; **no line of `mql5/` has ever been through a compiler**, and no order has
ever reached a broker. Until this is done, the execution half of the system is a hypothesis.

You need a Windows machine with MetaTrader 5 installed. There is no way around that —
MetaEditor is Windows-only, and the audit machine has neither.

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

Copy `mql5/Include/GoldDigger/` and `mql5/Experts/GoldDigger/` into the terminal's data folder
(File → Open Data Folder), then open `GoldDiggerBridge.mq5` in MetaEditor and press **F7**.

**Expect errors.** Roughly 2,000 lines have been written without a compiler ever seeing them.
What was verified mechanically: brace and parenthesis balance, that every function is defined
before use, and that every `StringFormat` call has as many arguments as specifiers. What could
not be: types, MQL5 API signatures, and every rule a compiler enforces that a text scan cannot.

Fix what it reports before going further. A warning about an unused variable can wait; anything
about a type or a signature cannot.

---

## 2 · Whitelist the dashboard

Tools → Options → Expert Advisors → **Allow WebRequest for listed URL**, and add the origin.

Scheme and host only — `https://your-dashboard.example.com`, no trailing path. A path there is
the usual cause of `WebRequest` error **4014**, which the EA logs with that exact explanation.

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

**Bars.** The Signals page has a price-feed panel. Both timeframes should appear; the entry
series needs roughly 100 bars before indicators read at all, so it may say WARMING UP briefly
after the first push.

```sql
select timeframe, count(*), max(open_time) from candles group by timeframe;
```

**A harmless command.** Queue Close All from Quick Actions with nothing open. It should reach
`done` in `trade_commands` within a poll or two. This proves the queue round trip without
risking a position.

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
  position alone.
- Alerting in place. See the audit's phase 2: right now nothing tells you the bot has stopped.
