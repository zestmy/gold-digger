# Backtesting

Replaying a strategy over the bars already stored, to find out whether it would have worked.

Until this existed, every strategy setting was an opinion. `ema_fast`, `adx_threshold`,
`sl_atr_multiplier`, the whole take-profit ladder — all of it configurable, none of it
measurable. This is what turns those numbers into something you can be wrong about.

---

## It calls the live evaluator

The single design decision the rest depends on.

`StrategyEvaluator` answers *"is there a signal on the most recent closed bar"*. Walking history
is therefore a matter of handing it progressively longer slices — never a second implementation
of the entry rules.

That matters because a backtester with its own copy of the logic drifts from the thing that
trades, usually without anyone noticing, and then its results describe a strategy nobody is
running. The exit side mirrors `TradeManager` for the same reason: rungs detected on bar close
and filled at market, the final target sitting on the order as a broker-side limit, break-even
once the first rung actually fills.

A test asserts the two agree on the same fixture. If they ever diverge, it fails.

---

## Every ambiguity resolves against the trade

A backtest is only worth running if it can say no. The ways one quietly says yes:

| Ambiguity | What this does | Why |
|---|---|---|
| When does a signal fill? | **Next bar's open** | The signal was produced *from* the current bar's close. Filling there is trading on the information that produced it. |
| A bar spans both the stop and the target | **Stop** | Without tick data the order is unknowable. Taking the target converts every losing bar into a winner. |
| A ladder rung is reached | **Fills at the bar's close**, not the rung | That is what the live system does — it notices on bar close and closes at market. Filling at the rung measures a system nobody built. |
| The broker-side final target | Fills **at** the level | It is a limit order, and limits do not slip. |
| Spread | Each bar's own, from `candles.spread_points` | A fixed spread hides that spreads widen exactly when a strategy is most likely to trigger. |
| Slippage | Adverse on every market order | Entries, stops, rungs and exits — never in your favour. |

Candle prices are treated as **bid**, which is what MT5 charts show. A buy enters at bid+spread
and exits at bid; a sell the other way. The spread is paid once per round trip, on the side that
really crosses it.

---

## Running it

```bash
php artisan backtest                    # the only active strategy
php artisan backtest 3 --trades         # a specific one, listing every trade
php artisan backtest --from=2026-01-01 --to=2026-03-01
php artisan backtest --spread=2.5 --slippage=0.5 --commission=7
php artisan backtest --json=storage/backtest.json
```

It **reads only**. No signal, trade or command row is written — a backtest that left rows behind
would poison the very analytics it exists to inform, and a test asserts the table counts are
unchanged.

Assumptions default from the terminal's own reported symbol specification: the pip size and pip
value on the latest heartbeat. Running with a pip value the broker does not use is measuring a
different instrument, and position sizing is a division by exactly that number.

---

## Reading the output

Most of the metrics match the Analytics page, deliberately, so a backtested strategy and a
traded one can be compared without translating between two vocabularies. Three are worth
singling out:

**Max drawdown.** Net profit says nothing about whether anyone could have sat through it. This
is measured on realised equity at each close, so it understates a dip that recovered inside an
open position — the honest limit of a close-to-close walk.

**Setups declined**, by reason, in the same vocabulary the live system records. Usually the most
useful part of the output: four trades from three hundred bars is normally a filter that is too
tight, and this says which one.

**Profit factor of `n/a`** means there were no losing trades. That is too few trades, not an
infinite edge, and it is reported as a blank rather than a number that invites belief.

---

## What it does not model

- **Swap**, so a strategy that holds overnight will look better here than it trades.
- **Requotes and rejections.** Every order fills.
- **Weekend and news gaps** as anything other than the next bar's open.
- **Partial fills.** Volume is always available.
- **Intrabar sequence.** The pessimistic assumptions above are the substitute for tick data,
  and they are assumptions, not measurements.

---

## Before you believe a result

**You need bars, and bars come from the Expert Advisor.** Until it has been compiled and run —
see [`COMMISSIONING.md`](COMMISSIONING.md) — there is nothing to test against. A backtest over
the few hundred bars from a first afternoon is a demonstration that the machinery works, not
evidence about a strategy.

**A backtest is a lower bound on how wrong you can be, not a forecast.** These assumptions are
pessimistic where the data is silent, which protects against the specific failure of optimising
a strategy into a curve that only existed in the sample. It does nothing about the sample being
unrepresentative.

**Optimising over one period fits that period.** See below — that is what walk-forward is for.

---

# Optimisation

```bash
php artisan backtest:optimise --param="ema_fast=10,15,20" --param="adx_threshold=20:30:5"
```

Walk-forward is the **default**. A plain sweep is what people reach for and what misleads them,
so getting one requires asking for it with `--sweep`.

## Why a sweep alone proves nothing

Any grid search over one series will find a combination that did well on it. With enough
parameters it will find one that did brilliantly. That result says the grid contains a curve
shaped like this stretch of history — which is true of any large grid and any stretch of
history. It is not evidence about the future, and **the more thoroughly you search, the less
evidence it is**.

The sweep still exists, because seeing the shape of the surface is useful. It just says so
about itself, every time it runs.

## What walk-forward does

Optimise on a stretch of history; test the winner on the stretch that came *next*, which the
optimisation never saw; roll forward and repeat. Each fold's out-of-sample result is a genuine
prediction, and stitched together they are the closest thing to an estimate of live behaviour
that history can offer.

Folds train on everything before them rather than a fixed rolling window — matching how you
would actually re-tune, with all the history available at the time.

### The numbers to read

**Degradation.** In-sample results are always good; that is what optimisation does. The
comparison is the finding. Out-of-sample close to in-sample means the edge may be real; much
worse or negative means the sweep fitted noise — *which is the normal result, and the reason to
run one*.

**Parameter stability.** Winners that jump between folds mean there is no stable optimum: the
surface is flat and the search is following noise. Weaker evidence than the out-of-sample
number, but strong evidence against.

**Whether it says anything at all.** Below 20 out-of-sample trades no verdict is offered in
either direction. An early version of this reported *"most of the optimised edge survived"* from
a **single** out-of-sample trade — exactly the over-claim the feature exists to prevent. That
floor is not a statistical threshold so much as a floor on embarrassment.

## How the ranking resists flattery

| Guard | Why |
|---|---|
| Minimum trade count | A combination that took four trades found four coincidences, not an edge. Below the floor it is not ranked at all. |
| Return measured against drawdown | Doubling an account through a 60% drawdown is not better than half the return through 5%, and net profit cannot see the difference. |
| Rank agreement reported | When metrics disagree about the winner, that disagreement *is* the finding — the ranking is being driven by noise. |
| Incoherent combinations dropped | A fast EMA at or above the slow one inverts every signal; a ladder out of order takes its rungs backwards. |

## It never writes anything

Not a signal, not a trade, not the strategy being optimised. Candidate parameters are applied to
a replica that does not exist in the database, so a search cannot rewrite what trades while
measuring what does not. A test asserts it.

## Keep the search small

`--max` refuses more than 400 combinations, which is a hint about method as much as runtime.
Two or three parameters at a time, validated out-of-sample, says something. Ten does not — it
just searches harder for a curve that fits.
