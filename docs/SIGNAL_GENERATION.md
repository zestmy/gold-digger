# Signal Generation

How the dashboard decides to enter a trade.

Until this existed, the handoff's summary was accurate: *"the EA executes; nothing enqueues
`open` commands except by hand."* This is the piece that enqueues them.

Read [`MT5_EA_BRIDGE.md`](MT5_EA_BRIDGE.md) first for how the EA and the dashboard talk, and
[`TRADE_MANAGEMENT.md`](TRADE_MANAGEMENT.md) for what happens to a position once it is open.

---

## The path a bar takes

```
 MT5 terminal              Laravel                                    MT5 terminal
 ┌──────────────┐          ┌──────────────────────────────┐           ┌──────────────┐
 │ bar closes   │──POST───▶│ candles                      │           │              │
 │ CopyRates(1) │ /candles │   ↓ new bar?                 │           │              │
 └──────────────┘          │ StrategyEvaluator            │           │              │
                           │   EMA cross + trend + ADX/ATR│           │              │
                           │   ↓                          │           │              │
                           │ filters → skip_reason?       │           │              │
                           │   ↓ none                     │           │              │
                           │ PositionSizer → lots         │           │              │
                           │   ↓                          │           │              │
                           │ signals row + trade_commands │◀──GET ────│ claims it    │
                           └──────────────────────────────┘ /commands └──────────────┘
```

The push and the poll happen on the same EA timer tick, so a bar that closes at 13:05:00 can
have its entry claimed on the very next poll rather than a bar later.

---

## Where the prices come from

**The executor's own feed.** The EA pushes closed bars from `CopyRates`; nothing fetches a
market-data API.

This is the one decision here worth not re-litigating. Indicators decide *where the stop
goes* — `sl_atr_multiplier * ATR`. If ATR is computed from one vendor's gold series and the
order is filled against the broker's, the stop is sized from prices the broker never quoted.
That is the pip trap arriving by a different route.

The `candles` table is still source-agnostic: `source` records who wrote each row, and
nothing downstream reads it. Backfilling history from a vendor for backtesting means writing
rows with a different `source` and changing nothing else.

---

## The entry rule

Straight from the `strategies` table — the columns were always there, nothing read them.

1. **Trend timeframe (H1)** sets the only tradeable direction: fast EMA above slow means
   longs only, below means shorts only.
2. **Entry timeframe (M5)**: the fast EMA must *cross* the slow one on the most recent
   closed bar, in that same direction.
3. **ADX and ATR** are attached to the setup but judged as filters, not rules — so what
   they reject is recorded.

A *cross*, not a state: "fast is above slow" stays true for hundreds of bars and would
re-enter on every one of them. And only the most recent closed bar — a cross three bars ago
is not tradeable now at anything like the price that justified it.

`strategies` stores an ADX threshold but no ADX period, so `atr_period` is used for both.
Both are conventionally 14.

---

## Filters

Every filter records the signal with a `skip_reason` and queues nothing. Only the first
objection is recorded: it is the gate that would have to change for the signal to trade.

| `skip_reason` | Meaning |
|---|---|
| `no_bot_settings` | The user has no `bot_settings` row |
| `bot_inactive` | The kill switch is off |
| `algo_trading_disabled` | The terminal's Algo Trading button is off — orders would return 10027 |
| `session_closed` | The bar closed outside `allowed_sessions` |
| `adx_below_threshold` | Trend too weak |
| `atr_below_threshold` | Below `min_atr_threshold` |
| `no_symbol_spec` | The terminal has not reported `pip_size` |
| `no_account_snapshot` | No heartbeat balance to size against |
| `max_trades_reached` | `max_concurrent_trades` already open |
| `daily_loss_limit` | Realised losses today past `max_daily_loss_percentage` |
| `lot_size_unavailable` | `pip_value_per_lot` unknown, so no honest size exists |

### Why rejected setups are still written down

`signals` exists to answer "if we had taken that signal, what would have happened?" and
"are the filters too strict?". Neither is answerable about signals that were never
recorded. Bars where the rules did not fire at all produce no row — otherwise the table
would gain one row per bar per strategy forever and the interesting rows would drown.

### The daily loss limit uses realised P&L only

Floating loss on an open position is not realised loss. A limit that tripped on unrealised
drawdown would halt trading over a position that recovers within the hour. The day's
opening balance is reconstructed as *balance now minus what today's closes did to it* —
`broker_accounts.last_balance` is a cache, and no daily-snapshot job exists.

---

## Position sizing

```
lots = (balance × risk%) / (stop distance in pips × pip value per lot)
```

Every trade then loses the same *amount* when it is wrong, regardless of how wide its stop
is. Fixed lots do the opposite: a wide ATR stop on a volatile day loses several times what
a quiet day's trade loses, so the worst losses cluster exactly where they hurt most.

`pip_value_per_lot` comes from the heartbeat and has **no default**. Absent it, the signal
is recorded `lot_size_unavailable`. The result is deliberately not snapped to the broker's
volume step — `CFXSExecutor::NormalizeVolume` already snaps *downward*, and rounding twice
could round up into more risk than the setting allows.

---

## What the order actually carries

The command carries `sl_pips` and `tp_pips` and leaves the absolute price columns **empty**.
`CFXSExecutor::Open` uses an absolute level verbatim when given one, so sending the price
computed here would anchor the stop to a bar close that is already seconds stale — the real
risk would differ from the intended risk by the gap. Sending pips lets the terminal place
the stop relative to the tick it fills at.

The price levels are still stored on the signal, because that is what the analytics pages
chart. The terminal's numbers remain authoritative.

**The order's target is the final ladder step**, `tp3_pips` (or `tp2_pips` when TP3 is
unset) — *not* TP1. Putting TP1 on the order would close the whole position at a level meant
to take only half of it, and TP2/TP3 would never be reached. The earlier rungs are taken by
[`TRADE_MANAGEMENT.md`](TRADE_MANAGEMENT.md), which watches bars and closes them at market.

---

## Idempotence

Three separate guards, because a duplicate entry is the expensive failure:

- **One signal per strategy per bar.** The EA re-pushes a trailing window on every new bar,
  so the same closed bar is seen repeatedly.
- **Evaluation only runs when a bar is new** to the series. A push of entirely known bars
  stores them and stops.
- **The command's idempotency key is `signal:{id}`**, so `TradeCommand::enqueue` collapses
  any repeat into the one existing row.

`was_executed` is set on the *fill*, not on the enqueue. A queued command is an intention;
it can still expire or be rejected. A signal with no skip reason and no trade is one still
in flight — a state worth being able to see.

---

## Running it by hand

```bash
php artisan signals:generate --explain
```

Evaluates every active strategy against whatever candles are stored, and writes exactly what
the API path writes. `--dry-run` reports what the entry rules see without persisting
anything; `--strategy=` narrows to one.

Useful because the normal trigger only fires while a terminal is attached and pushing, which
is a poor place to debug "why did that bar not produce a signal".

---

## Inline, or queued

By default a candle push evaluates strategies **inside the request**. At one symbol on M5 —
a few hundred bars of arithmetic once every five minutes — that costs nothing worth saving, and
it needs no background process to be running.

`QUEUE_STRATEGY_EVALUATION=true` moves it to a job instead: the push stores its bars, answers,
and a worker does the thinking.

```bash
php artisan queue:work --queue=strategy
```

Worth turning on when a single push starts doing real work — several symbols, several
strategies, or a faster entry timeframe — because `WebRequest` blocks the terminal's event
thread for as long as the request takes.

**It requires a worker.** Without one, bars are stored, jobs pile up, and the bot stops trading
while the executor heartbeats happily and this page simply stays empty. That failure is silent,
which is why the health monitor raises `queue_stalled` — and why the switch is off by default
rather than being turned on for you.

The job runs the same call sequence as the inline path, positions before entries, so the switch
changes *when* the work happens and never *what* it does. A test asserts both produce the same
signal from the same bars. Jobs are unique per account and timeframe, so a burst of pushes
collapses to one evaluation.

---

## More than one instrument

A strategy names its symbol in the abstract — `XAUUSD` — and `symbol_specs` records what each
broker actually publishes it as, along with that instrument's pip size, pip value and volume
limits. `SymbolResolver` is the single place that translation happens.

That table is why a second instrument is now configuration rather than a schema change. Pip
size and pip value used to live on `bot_heartbeats`, which has room for exactly one symbol's
figures and was read directly by three separate parts of the strategy layer.

Specs arrive **with the bars they describe**, on the candle push, so every symbol with price
history has one and the two cannot drift apart or arrive in the wrong order.

The heartbeat still answers for its own resolved symbol, so an upgrade does not strand a
running deployment — but only for that symbol. Lending one instrument's pip value to another
would size a position from a different market's numbers, which is a silently wrong trade rather
than a visible failure.

**Multi-symbol means one executor per symbol.** Each Expert Advisor instance resolves its own
`BaseSymbol`, pushes its own bars and reports its own spec, against its own token. That is a
deliberate choice over teaching one EA to loop: the instances are isolated, and it needs no
refactor of code that has never been compiled.

---

## Not built

- **News filter.** `news_filter_enabled` is stored; there is no news source.
- **`confidence_score`** is always null. Nothing scores setups yet.
- **Backtesting.** The evaluator answers "is there a signal now", not "where were all the
  signals". Walking history deliberately is a different job — the schema supports it
  (`candles` plus `signals.features`), nothing implements it.

---

## What has not been verified

**The EA's candle push has never run.** It has never been compiled — the same caveat that
covers the rest of `mql5/`, and for the same reason: no MetaEditor, no Windows terminal.
Everything on the Laravel side is covered by tests, including the full path from a candle
push to a queued command, but no real bar has ever made this round trip.

Worth watching on first attach:

- **`EntryTimeframe` / `TrendTimeframe` must match the strategy's.** If they disagree the
  bars are stored and nothing is ever generated, which looks exactly like a broken strategy.
- **The first push needs `HistoryBars` ≥ ~100** or the indicators never warm up. ADX alone
  needs `2 × period` bars before it reads at all.
- **`bot_logs` will show the push failing** before anything else does. If `/logs` is silent,
  the EA is not reaching the API at all.
