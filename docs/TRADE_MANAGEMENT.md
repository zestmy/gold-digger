# Trade Management

What happens to a position after it opens.

[`SIGNAL_GENERATION.md`](SIGNAL_GENERATION.md) covers the entry side. This is the other half:
the take-profit ladder, the reversal and time exits, and the break-even stop — the
`strategies` columns that were stored and unread until now.

---

## The trigger is the same bar close

```
 bar closes ──▶ POST /candles ──▶ manage open positions ──▶ generate entries
                                   │                          │
                                   ├─ rung reached? ──────────┤
                                   ├─ reversal?               │
                                   ├─ held too long?          ▼
                                   └─ break-even?        trade_commands
```

Management runs **before** entry generation, so a reversal or timeout exit is queued ahead
of the same bar's new entry — the EA then claims them in that order: out of the old trade,
then into the new one.

---

## Why the ladder is detected here at all

MT5 puts **one** take-profit on a position. Signal generation gives it the *final* rung, so a
level meant to take half the position does not close all of it. Everything before that final
rung therefore has to be noticed here and closed at market.

### The cost of that, stated plainly

A rung is detected when the bar that touched it **closes**, and filled at whatever the market
is a moment later. If price spiked through TP1 and came back inside the same bar, the fill is
worse than TP1 — possibly much worse.

The stop stays broker-side throughout, so this costs *profit*, not capital. But it is a real
difference between the ladder as configured and the ladder as executed, and it grows with the
entry timeframe: an M5 bar can travel a long way through a 30-pip target.

**The precise alternative** is to open one position per rung, each with its own broker-side
TP. Exact, no latency, no detection needed. It also triples position count and margin, breaks
the one-trade-one-ticket assumption in `trades`, and makes `max_concurrent_trades` mean
something different. Worth revisiting deliberately; not worth doing quietly.

---

## Close percentages are shares of the *initial* position

`strategies` defaults to **50 / 30 / 20**, which sums to exactly 100 and is only coherent as
fractions of what was opened.

The migration's own comment says TP2 closes its share "of REMAINING". That contradicts the
defaults: 30% of the 50% left after TP1 is 15% of the position, and a TP3 of 20% would then
leave a third of the trade open forever. **The defaults are taken as the intent and the
comment as the mistake.** The UI says only "TP1 Close %", so nothing outside the schema
disagrees.

If you meant the comment rather than the defaults, this is the line to change — and the
defaults should change with it.

---

## What gets queued

| Condition | Command | Volume |
|---|---|---|
| Price traded through TP1 | `close`, reason `tp1` | `tp1_close_pct` of initial |
| Price traded through TP2 *(only when a TP3 exists)* | `close`, reason `tp2` | `tp2_close_pct` of initial |
| EMAs crossed against the position, `exit_on_reversal` on | `close`, reason `reversal_exit` | all remaining |
| Bars since entry ≥ `max_holding_bars` | `close`, reason `time_exit` | all remaining |
| TP1 **filled**, stop not yet at break-even | `modify`, reason `break_even` | — |
| Profit past `trail_trigger_pips`, and the trail is tighter than the current stop | `modify`, reason `trail` | — |

The final rung is never queued: it is the level on the order, and the broker closes the
remainder without being asked. Which level that is depends on the trade — TP3 when it has
one, otherwise TP2.

**Exits supersede rungs** on the same bar. An exit takes the whole position, so pairing it
with a partial would queue two commands where one does the job, and the partial's fill would
move the exit's.

**Exits never expire**, unlike entries. An entry that waited out its bar is no longer the
trade the strategy intended; an exit that is late is still the exit, and expiring it would
leave open a position something decided should not be.

---

## Break-even waits for a fill, not a command

The stop moves to entry only once a TP1 partial appears in `trade_partials` — that is, once
the broker has confirmed a real deal. Moving it when the partial is merely *queued* would put
the **whole** position on a break-even stop, which is a different trade from the one the
strategy described.

The stop goes to the entry price exactly. That is not quite free — commission and swap are
still owed — but "break-even" meaning "entry" is what the term is understood to mean, and
padding it by an invented number of pips would be a rule nobody configured.

---

## Trailing, and a break-even that breaks even

Both are **off by default**. They change P&L, and a setting that changes P&L should not arrive
switched on.

### The trail

Set `trail_trigger_pips` and `trail_distance_pips` — either left blank disables it. Once the
position is at least the trigger in profit, the stop follows to `distance` behind the best
price seen.

**Behind the best price, not the last close.** A stop that followed the close would loosen on
every pullback, which is not a trailing stop but a drifting one.

**It only ever moves toward profit.** A proposed level that would loosen the stop is discarded:
the risk on a position was decided when it opened, and no rule here is allowed to widen it.

The idempotency key carries the level, so a trail issues one command per distinct stop rather
than one per bar — keyed on the trade alone, only the first move would ever happen.

### Break-even offset

Moving the stop to exactly the entry leaves the trade losing what it paid to get there: the
spread crossed on entry, commission both sides, any slippage. On a gold scalp that is a real
share of a 30-pip first target. `breakeven_offset_pips` moves the stop that much further into
profit, so the phrase means what it says. Zero — the default — preserves the old behaviour.

### Measure it before believing it

Both settings are sweepable, and the backtester models them identically to the live manager:

```bash
php artisan backtest                                    # the baseline
php artisan backtest:optimise --param="trail_trigger_pips=40"                              --param="trail_distance_pips=15,30,60"
```

On a run-then-collapse series a tight trail cuts winners short and a loose one never fires;
the point is that this is now a measurement rather than an argument. A trailed exit is reported
as `trailing_stop` in the exit breakdown, distinct from `sl` and `break_even_stop`, so you can
see whether trailing is protecting gains or ending trades early.

---

## Positions too small to divide

Half of the broker's minimum lot is not a tradeable volume: `CGDExecutor::NormalizeVolume`
snaps it to zero and the close fails. A rung is skipped when either the share itself or the
remainder it would leave falls below `volume_min` (reported on the heartbeat).

Such a position runs to its final target whole. That is the honest outcome for a 0.01-lot
trade, and better than a failing command at every rung of every trade.

---

## Idempotence

Every action carries a fixed key — `close:{trade}:tp1`, `modify:{trade}:break_even` — so
`TradeCommand::enqueue` collapses a repeat into the row that already exists.

That is what makes it safe to re-check rungs against **every bar since entry** rather than
tracking which rungs have been taken: a rung reached while the dashboard was down is still
acted on when it comes back, and one already taken is a lookup rather than a second close.

This is the most expensive duplicate in the system. A duplicated entry costs one unwanted
position; a duplicated close on a laddered trade closes more than the strategy asked for, and
the remainder that was supposed to run to the final target is simply gone.

---

## The reason round trip

`trade_partials.close_reason` is what makes the ladder visible afterwards, and a broker deal
cannot supply it — MT5's `DEAL_REASON` only distinguishes "an expert did this" from a stop or
a target. So the rung travels on the command, and the EA echoes it back with the fill.

The wire has carried a `reason` column since the protocol was written; **the EA never read
it**, so before this every commanded close was recorded as `manual`. It now remembers the
reason against the ticket and consumes it when the deal arrives.

One consequence worth knowing: the EA labels a **broker-side** take-profit `tp3`, because the
order always carries the final rung and the terminal never saw the ladder. When the strategy
set no TP3, `FillController` corrects that to `tp2`. `TradeManager` never commands `tp3`, so
the correction cannot collide with a commanded close.

---

## Running it by hand

```bash
php artisan trades:manage
```

Checks open positions against the ladder and the exit rules and queues what they need.
There is no dry run, unlike `signals:generate`: every action is idempotent on a fixed key, so
running it repeatedly cannot produce a second close, and a mode that queued nothing would
answer a different question from the one being asked.

---

## Not built

- **Trailing stops.** The stop moves once, to break-even. Nothing trails it behind price.
- **Partial-close accounting against `tp3_close_pct`.** The final rung closes whatever
  remains, so the column is descriptive rather than enforced.
- **Reconciliation.** A position closed at the broker while the EA was detached is still
  back-filled only by `OnTradeTransaction` when it reattaches, and a position opened outside
  the bot is not in `trades` at all, so nothing manages it.
- **Intrabar detection.** See the cost section above.

---

## What has not been verified

Everything Laravel-side is covered by tests, including the full path from a candle push to a
queued rung to a recorded `trade_partials` row.

The EA side has **never been compiled** — this adds `CGDExecutor::ModifyPosition`, the
`modify` command handler, and the close-reason store to code that had already never seen a
compiler. Watch for, on first run:

- **`modify` rejected with 10016.** A broker's stops level is measured from the *current*
  price, so a break-even stop is refused exactly when price has come back near entry — which
  is when it is being asked for. `ClampStops` handles it, and this is the first place to look
  if the stop never moves.
- **A rung recorded as `manual`.** That means the reason did not survive the round trip: the
  EA did not read column 11, or the deal arrived without a remembered reason.
