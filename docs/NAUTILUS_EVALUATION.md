# NautilusTrader, read against FXSignal Pro

[NautilusTrader](https://nautilustrader.io) is an open-source, event-driven trading platform:
a Rust core with Python as the control plane, one kernel shared by backtest, sandbox and live,
nanosecond timestamps, and about twenty venue adapters
([repo](https://github.com/nautechsystems/nautilus_trader), LGPL-3.0, Python 3.12-3.14).

This is an evaluation, not a plan of record. It exists because "should we be on Nautilus?" is a
question that will be asked again, and the useful answer is not yes or no — it is *which parts of
it we are already doing, which parts are worth copying, and which parts would cost more than they
return here*.

**The short version.** Do not port. Nautilus has no official MetaTrader integration, and the
execution path is the one part of this system that has actually been verified against a broker.
What is worth taking is a handful of ideas about **simulation honesty** — latency, probabilistic
fills, instrument precision — each of which is a small change to `app/Services/Backtest` and each
of which makes a backtest here harder to fool. One of them found a live parity defect while this
document was being written; see [Volume is snapped in one place only](#volume-is-snapped-in-one-place-only).

> **Since written:** items 1 and 2 of the [recommendation](#recommendation) are built — volumes
> now snap in the dashboard and sub-minimum sizes are declined rather than inflated, and every
> simulated market order pays for the queue's delay, calibrated from `trade_commands`. See
> `docs/BACKTESTING.md` and `docs/SIGNAL_GENERATION.md`. The rest stands as written.

---

## What we already agree on

Worth stating first, because the interesting comparisons are the ones where we are not behind.

| Nautilus does | We do |
|---|---|
| One kernel for backtest and live, so parity is structural rather than remembered | `Backtester` calls the live `StrategyEvaluator` and mirrors `TradeManager`; a test fails if they diverge. `docs/BACKTESTING.md` |
| Pre-trade risk checks as a distinct gate | `SignalGenerator::firstObjection()` — a dozen ordered gates, and the one that stopped a setup is recorded as `skip_reason` |
| Execution reports reconciled against internal state | `PositionReconciler` against EA snapshots. `docs/RECONCILIATION.md` |
| Commands idempotent, states explicit | `trade_commands.idempotency_key`, and a five-state enum with `attempts` and `expires_at` |
| Fail loudly rather than silently continue on bad data | `no_symbol_spec`, `no_account_snapshot`, stale-calendar hold in `docs/NEWS_FILTER.md` |

The architectural instinct is the same one. That is the reason a port buys less than it looks like
it should: the expensive idea in Nautilus — *one evaluator, two environments* — is already the
load-bearing decision in `docs/BACKTESTING.md`.

---

## What it does better, and what to take

Ranked by value per unit of work. The first three are days, not weeks, and none of them touch the
executor.

### 1. Latency is not modelled here at all

Nautilus ships a `LatencyModel` for exactly this: configurable delays between submitting an order,
the venue acknowledging it and the fill coming back.

Our decision-to-fill path is not fast, and it is not guessable either:

```
bar closes -> EA timer fires (<= PollSeconds, default 5) -> HTTP push
           -> dashboard evaluates, queues a row in trade_commands
           -> EA timer fires again (<= PollSeconds) -> claim -> order_send
```

Two poll intervals plus two round trips. On the default `PollSeconds = 5` that is **5-10 seconds**
between the bar close that produced the signal and the order reaching the broker. The backtester
fills that order at the next bar's open — which, bars being contiguous, is the price *at the moment
of the close*. Gold moves several pips in ten seconds often enough to matter to a strategy whose
stop is measured in tens of pips.

So the pessimism in `MarketAssumptions` — adverse slippage on every market order, each bar's own
spread, stop-before-target inside a bar — has a hole in it precisely where our architecture is
slowest, and the hole is on the flattering side.

The fix was cheap and needed no guess, because **we already store the measurement**.
`trade_commands` carries `created_at`, `claimed_at` and `completed_at`, and `result` carries the
fill price, so the median queue wait is a fact about this deployment rather than an assumption
about one. `MarketAssumptions::latencySeconds` now carries it — that median plus one nominal poll
interval for the bar-push leg, which has no timestamp of its own — falling back to ten seconds
until ten claimed commands exist. Entries and dashboard-decided market exits pay that share of
the bar's adverse excursion; the stop and the final target do not, because they sit on the order
at the broker. `--latency=` makes the sensitivity visible: a strategy that only works at zero
latency is not a strategy we can run through a 5-second poll.

That also gives `PollSeconds` a number instead of a shrug — if backtested edge collapses between
2s and 8s, the poll interval is a strategy parameter and belongs in the tuning discussion.

### 2. Fills are certainties here and probabilities there

Nautilus's `FillModel` is explicitly probabilistic: `prob_fill_on_limit`, `prob_fill_on_stop`,
`prob_slippage`, `random_seed`. The seed is the point — the run is reproducible, but the fills are
not all free.

Ours are all free. `docs/BACKTESTING.md` is honest about it ("Every order fills", "Requotes and
rejections" unmodelled), and two of those matter more than the doc's tone suggests:

- **The broker-side final target "fills at the level, because limits do not slip."** True about
  price, false about occurrence — price can touch a limit and leave without filling it, and the
  bar we would score as a win is then a position still open when the reversal arrives. Every
  trade that ends at the final target is, in the simulation, a certainty.
- **Rejections.** `Executor.mqh` retries on requote and price-changed, and `docs/MT5_EXECUTION.md`
  exists because rejections are common enough to rank. In the backtest they never happen.

We do not need Nautilus's model, only its shape: a `FillModel` value object alongside
`MarketAssumptions`, seeded, with a probability of a touched limit not filling and a probability of
an entry being rejected outright. The default can stay at 1.0/0.0 so nothing changes for an
existing run — the value is in being able to ask what a 90%-limit-fill world does to the ladder,
and in the answer being repeatable because the seed is stored in the report.

### 3. Volume is snapped in one place only

This one is not a modelling nicety, it is a live parity defect, and the Nautilus lens is what
turns it up: Nautilus applies each instrument's `size_increment` and minimum quantity when the
order is *constructed*, inside the shared core, so backtest and live necessarily trade the same
quantity.

We snap in the executor and not in the simulation, deliberately, and the note saying so is in
`PositionSizer`:

> The result is deliberately *not* snapped to the broker's volume step. Only the terminal knows
> the step, and `CFXSExecutor::NormalizeVolume` already snaps downward — rounding here as well
> would round twice.

The reasoning is right about double-rounding and wrong about the consequence, because
`NormalizeVolume` does not only round down:

```cpp
double snapped = MathFloor(volume / m_vol_step) * m_vol_step;
if(snapped < m_vol_min) snapped = m_vol_min;     // <- rounds UP, past the risk setting
if(snapped > m_vol_max) snapped = m_vol_max;
```

So for a small account, or a wide stop, or a low risk percentage — any of the three — a backtest
that sizes 0.004 lots is scored on 0.004 lots and traded at `m_vol_min`, typically 0.01. That is
**two and a half times the risk the setting asked for**, on the one side of the ladder where the
account is least able to take it, and the backtest reports the smaller number. In the ordinary
case the error is the other way and smaller (0.037 sized, 0.03 traded, ~19% less P&L than
simulated), which is survivable but still means the equity curve is not the one that would have
been traded.

`symbol_specs` and `bot_heartbeats` already carried step and minimum, so both halves of the fix
were short: `App\Services\Trading\VolumeRules` is now the one copy of the EA's floor-onto-the-step
arithmetic — shared by the strategy path, the backtester and the three copier call sites that had
each grown their own — and where the terminal would raise a sub-minimum size, the dashboard
declines it as `below_min_volume` instead. Rounding twice is harmless when both roundings are
`floor` onto the same grid; trading more than the setting allows is not.

### 4. Bar ambiguity as a switch rather than a constant

Inside one bar we always resolve stop-before-target. That is the right default and
`docs/BACKTESTING.md` defends it well. Nautilus makes the same ambiguity a venue option
(`bar_adaptive_high_low_ordering`), which buys something a constant cannot: the **spread between
the pessimistic and optimistic readings of the same data**. A strategy whose profit factor is 1.1
pessimistic and 2.8 optimistic is a strategy whose result is an artefact of intrabar sequence, and
we currently cannot see that number. `--ordering=pessimistic|optimistic` reports it for a day's
work, and it is directly relevant to the known intrabar-exit gap in `docs/HANDOFF.md`.

### 5. A clock you can inject

Nautilus separates `TestClock` from `LiveClock` and puts nanosecond timestamps on every event, so
time-dependent logic is tested by advancing a clock rather than by arranging for the wall clock to
cooperate. We have session windows, news blackouts, time-based exits, break-even timing and a
reconciliation cadence — all time-dependent, and five test files reach for `Carbon::setTestNow`.
That works; a clock passed in works better, and the place it would pay is the news filter, where
the interesting cases are all "the bar closed 90 seconds before the release".

### 6. Portfolio as derived state, not a polled snapshot

Nautilus keeps a `Portfolio` component that updates balances, exposure, margin and unrealised P&L
from the event stream. We read balance and equity off the latest `bot_heartbeats` row, which means
account state is as old as the last poll and `daily_loss_limit` is evaluated against it. For a
limit whose whole job is to trip promptly, deriving equity from our own open positions and known
fills between heartbeats — and treating the heartbeat as the correction, not the source — is the
more defensible order of trust.

### 7. A columnar catalogue for deep history

`RunStrategyImprovement` walks twenty thousand bars, and `docs/MARKET_DATA.md` records the decision
not to store deep history — fetch from Twelve Data on demand, never persist. Nautilus's
`ParquetDataCatalog` is the other answer: write the fetched series once to a columnar file, replay
it repeatedly, and get reproducibility for free — a sweep re-run next month reads the same bars
rather than whatever the vendor now says about March. That is a *revisit if*, not a recommendation:
if walk-forward runs become routine, or if a vendor revision ever silently changes a published
result, the trade-off flips.

---

## Where an actual Nautilus deployment could pay for itself

Not as the trading system. As a second opinion on the questions our bar-bound simulation cannot
answer:

- **Intrabar exits.** The largest known gap in `docs/HANDOFF.md`: rungs are detected on bar close
  and filled at market, so a spike that retraces fills worse than the rung, and we cannot say by
  how much. Nautilus replays quote ticks at nanosecond resolution. Porting the ladder there and
  running it over tick data would put a number on the cost of bar-bound detection, which is what
  decides whether intrabar detection in the EA is worth building.
- **Fill-model sensitivity**, done properly, over an order book rather than an assumption.
- **Parameter search at a scale PHP will not reach.** The project's own claim is that it is fast
  enough to train RL agents; our walk-forward takes minutes and runs on a queue worker.

The honest cost of that: a second implementation of the strategy, in a second language, which is
the exact drift `docs/BACKTESTING.md` was written to prevent. It is worth paying only for a
bounded question with an answer that changes a decision — "what does intrabar detection buy" is
one. "Let's have a Python backtester too" is not.

### If execution ever did move

There is an unofficial community adapter, [`mt5-connector`](https://pypi.org/project/mt5-connector/)
(MIT, beta, actively released through 2026), bridging Nautilus to any MT5 broker. Read the
requirements before getting attached: it is Windows-only because it is built on the `MetaTrader5`
Python package, and it is netting-mode only.

That Python package is the IPC wrapper around a running terminal that this project **already
abandoned**. `ARCHITECTURE.md` names the failure class — terminal not attached, privilege
mismatch, `order_send` returning `None`, no Linux hosting — and `bot/` survives as a diagnostic
precisely because running *inside* the terminal removed all of it. Adopting a Nautilus MT5 adapter
reintroduces that class, and adds an unofficial dependency between our strategy and our broker.

The supported route to a Nautilus execution path is a different venue: Interactive Brokers, where
gold is a CFD or a futures contract rather than a broker's XAUUSD. That is a change of instrument,
account type, margin regime and regulatory posture — a business decision that happens to have a
software consequence, not a refactor.

---

## Costs to price in before anyone proposes a port

- **Language and runtime.** PHP 8.2 + MQL5 today, one deployment story. Nautilus adds Python
  3.12-3.14 and a Rust-built wheel, on the Windows VPS where the terminal lives.
- **Licence.** LGPL-3.0. Using it from Python is unproblematic, including commercially; modifying
  the library and shipping the result carries obligations. Worth a lawyer's five minutes before it
  is load-bearing in a hosted product, not before a research spike.
- **Two strategy implementations.** The drift risk above, in a tree whose central design decision
  is that there is only ever one evaluator.
- **Where this project actually is.** `docs/HANDOFF.md`: the kill switch has not been turned on,
  `TradeManager` has never run against a broker, and `DemoOnly` has not been cleared. Nothing in
  Nautilus helps with any of that, and a platform migration before the first full ladder has
  executed would replace a verified execution path with an unverified one to fix problems we have
  not measured yet.

---

## Recommendation

| # | Change | Effort | Why now |
|---|---|---|---|
| 1 | ~~Snap simulated volume through the EA's arithmetic; refuse sub-minimum sizes~~ **done** | Hours | Live parity defect, and the error direction is more risk than configured |
| 2 | ~~`latencySeconds` in `MarketAssumptions`, calibrated from `trade_commands` timings, with a `--latency=` sweep~~ **done** | ~1 day | Our slowest link is unmodelled on the flattering side; the data to calibrate it is already stored |
| 3 | Seeded `FillModel`: limit-fill and rejection probabilities, defaults preserving today's behaviour | ~1 day | Removes the last "every order fills" certainty; seed keeps runs reproducible |
| 4 | `--ordering=optimistic` to report the intrabar spread | ~1 day | Quantifies how much of a result is intrabar artefact; informs the intrabar-exit decision |
| 5 | Inject the clock instead of `setTestNow` | ~1 day | Makes news-window and session edges directly testable |
| 6 | Derive equity between heartbeats, heartbeat as correction | ~2 days | `daily_loss_limit` currently trips against state up to a poll old |
| 7 | Nautilus spike: ladder over tick data, tick-vs-bar exit cost | ~1 week | Only after 1-4, and only to answer the intrabar question with a number |

1-6 are our own code, in our own language, and each leaves the system simpler to argue about.
7 is the only one that adds a dependency, and it should be a throwaway.

**What not to do:** replace the MQL5 executor, run live through an unofficial MT5 adapter, or
maintain a Nautilus strategy alongside the PHP one. The first discards the only verified part of
the system, the second reintroduces a failure class we paid to escape, and the third breaks the
rule that keeps the backtester worth reading.

---

## Sources

- [NautilusTrader](https://nautilustrader.io) — project site
- [nautechsystems/nautilus_trader](https://github.com/nautechsystems/nautilus_trader) — README: features, adapters, licence, supported Python versions
- [Architecture](https://nautilustrader.io/docs/latest/concepts/architecture/) — kernel, message bus, cache, data/risk/execution engines, portfolio, environment contexts
- [nautilus-backtest](https://crates.io/crates/nautilus-backtest) — the backtest crate
- [mt5-connector](https://pypi.org/project/mt5-connector/) — unofficial community MT5 adapter
