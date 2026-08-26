# Reconciliation

Making `trades` agree with what the account actually holds.

---

## Why this became necessary

The dashboard only ever learned about positions it opened, and only when the report got
through. Everything else was invisible:

- a position opened by hand in the terminal;
- one opened by the bot while the API was unreachable;
- one closed at the broker — stop or target — while nothing was running.

That was survivable when the dashboard was a viewer. It stopped being survivable when
[`TRADE_MANAGEMENT.md`](TRADE_MANAGEMENT.md) started *issuing commands from those rows*. A row
claiming a position that no longer exists produces close commands against a dead ticket for
ever; a live position with no row is managed by nothing at all.

---

## A snapshot, not events

`POST /api/v1/bot/positions` carries everything the terminal currently holds.

Events are what `/fills` already does, and events are exactly what goes missing — a position
opened or closed while nobody was listening produced no event anyone received. A snapshot is
self-correcting: whatever was missed, the next one states the truth outright.

```
 EA (on attach, then every ReconcileMinutes)
   │
   ├─ replay closing deals from the last N days ──▶ POST /fills   (keyed on deal ticket,
   │                                                               so repeats are ignored)
   └─ snapshot of open positions ────────────────▶ POST /positions
                                                     │
                                    ┌────────────────┼────────────────┐
                                    ▼                ▼                ▼
                                 adopt            refresh           close
                              (unknown)      (volume, stop, P&L)  (vanished)
```

The replay runs **first**, so a position that has already gone is settled with its real P&L
before the snapshot reports it missing and the dashboard has to close it without figures.

---

## The magic number is the scope

A snapshot means *"these are all the positions carrying this magic, on this account"*. That
boundary is the only thing that makes absence meaningful — without it, "not in the list"
cannot be distinguished from "not covered by the list".

So:

- **Outside the scope, nothing is concluded.** Another account, another EA's magic — the
  snapshot says nothing about them and they are left alone.
- **A snapshot with no magic still adopts and refreshes what it names, but closes nothing.**
  Concluding from absence there would let one EA close a second EA's positions.
- **Rows with no recorded magic are covered** when a magic is given: those predate magic
  numbers being stored and belong to this bot.

---

## Adopted positions are never managed

A position found on the terminal is recorded with `origin = 'adopted'`, and `TradeManager`
only ever manages `origin = 'bot'`.

This is the single most important property here. `strategy_id` is `NOT NULL`, so an adopted
position has to be attributed to *some* strategy just to be storable — and if that attribution
were enough to make it managed, `max_holding_bars` would close a position somebody opened by
hand, 24 bars after it appeared. Closing someone's manual trade because it showed up in a
table is the worst thing this feature could do.

The Live Trades screen labels these **ADOPTED · UNMANAGED** for the same reason.

---

## What it refuses to invent

**A vanished trade is closed but not scored.** It is marked `fully_closed` with
`closure_reason = 'reconciled_closed'` and a note; the money columns are left at whatever was
last reported. They are not zeroed and no P&L is estimated — the figures were never observed,
and `closure_reason` is what says so. Where the deal history reaches far enough back, the
replay through `/fills` supplies the real numbers instead.

**A position with no stop records no stop.** MT5 reports an unset stop as `0.0`, which is not
a level at zero. `trades.sl_price` became nullable for this: storing the zero would chart a
stop the position does not have, and refusing to adopt would leave a live position invisible.

The original reasoning — *"a position with no stop is a bug worth failing loudly on"* — still
holds for every position **this system opens**. It does not hold for one found on a terminal
where a person may have opened it by hand.

---

## What it corrects on positions it already knows

The terminal is right and the table is wrong:

| Field | Why it can drift |
|---|---|
| `remaining_lot_size` | A partial close whose report never arrived. Less open than recorded also flips the status to `partially_closed` |
| `sl_price` | A stop moved by hand in MT5 |
| `gross_pnl_money` | So an open position's floating figure on the dashboard is the broker's |

---

## EA settings

| Input | Default | Meaning |
|---|---|---|
| `Reconcile` | `true` | Send position snapshots at all |
| `ReconcileMinutes` | `15` | Minutes between snapshots |
| `ReplayHistoryDays` | `3` | How far back to re-report closes when the EA attaches |

This is a correction, not a feed — `/fills` still reports every event as it happens. The
snapshot only catches what no event covered, which is why it runs on attach and then rarely.

---

## Running it by hand

There is no artisan command. Reconciliation is driven entirely by what the terminal reports,
and nothing on this side can enumerate positions without it — a command would have nothing to
ask.

To check the result: `trades` rows with `origin = 'adopted'` are what was found, and
`closure_reason = 'reconciled_closed'` is what was closed for having vanished.

---

## Not built

- **Adopting into a strategy properly.** An adopted position is attributed to a strategy only
  to satisfy `NOT NULL`, and is then excluded from management. There is no way to say "yes,
  manage this one" from the UI.
- **Backfilling the entry deal.** The replay reports closes only. An opening deal would create
  a trade row with no strategy and no levels, which is what the snapshot does properly.
- **Pips on replayed closes.** The replay sends `pips_profit` as 0 rather than deriving it,
  because the entry price is not reliably in the same history selection. A wrong pip figure
  is worse than an absent one.

---

## What has not been verified

The Laravel side is covered by tests, including adoption, refresh, the scoping rules and the
full three-way correction.

The EA side has **never been compiled** — `FXSReportPositions` and `FXSReplayClosedDeals` are
new code in a file that has never seen a compiler. On first run, watch for:

- **Nothing being adopted.** The snapshot filters on `MagicNumber`, so positions opened by a
  different EA — or by hand — carry a different magic and are correctly not reported. That is
  the intended behaviour and also the most likely surprise.
- **A flood of `closed` fills on attach.** Expected once: the replay covers `ReplayHistoryDays`
  and `/fills` ignores deals it already has, keyed on the deal ticket.
