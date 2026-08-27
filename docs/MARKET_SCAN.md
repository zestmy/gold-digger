# Market Scan

`/analysis` scans every instrument this account has stored bars for, ranks them on measured
evidence, and proposes the ones worth attention. One of them can then be opened for its
structure, its levels and a plan.

It places nothing. Taking one of these is a separate deliberate act by a person.

---

## The honest version of the question

"Which pair is profitable" is not answerable, and nothing here pretends to answer it. The
answerable version is:

> Of the instruments there is history for, which currently have the most independent things
> agreeing about a direction, a level close enough behind price to put a stop beyond, and
> one far enough ahead to be worth aiming at?

That is arithmetic, so it is computed rather than asked for. The ranking exists with no API
key, no credit and no network.

---

## Two halves, and only one of them costs anything

| | Measured ranking | The proposal |
|---|---|---|
| Produced by | [`MarketScanner`](../app/Services/Analysis/MarketScanner.php) | [`ScanAnalyst`](../app/Services/Ai/ScanAnalyst.php) |
| Cost | Nothing. Database only. | One model call for the whole scan |
| Checkable | Yes — recomputable from stored bars | No. It is judgement, and the card says so |
| Fails to | — | The measured table, unchanged |

The checkbox on the page turns the second half off. That is a real choice rather than a
degraded one: somebody who trusts their own reading of a confluence table should not have to
pay for a paragraph about it.

---

## What the scanner measures

For each instrument with at least `MarketScanner::MIN_BARS` (60) stored bars on the scan
timeframe:

1. **Direction** comes from the entry-timeframe EMAs, through
   [`MarketContext`](../app/Services/Strategy/MarketContext.php). EMAs level with each other
   is no direction, and the instrument is skipped with that as its stated reason rather than
   scored in both directions — scoring both would be scoring neither.

2. **Confluence** comes from [`SignalQuality`](../app/Services/Strategy/SignalQuality.php),
   which is the same scorer the copier grades entries with. A second scoring function would
   drift from it, and the first sign of the drift would be this page recommending an
   instrument the executor then refuses to trade for reasons this page never mentioned.

3. **Levels** come from [`Structure`](../app/Services/Indicators/Structure.php): pivots found
   by definition and merged when within half an ATR, so a level tested three times counts as
   one and carries the count.

4. **The plan** is built from those levels and nothing else:
   - **Entry** is the last stored close.
   - **Stop** sits `0.25 × ATR` beyond the nearest level behind price. A stop exactly on a
     level is taken out by the wick that tests it, and a buffer in ATR is generous on gold
     and generous on an index, which a fixed number of points is not.
   - **Target** is the nearest level ahead that is at least `0.5 × ATR` away. The very next
     level is sometimes a few ticks off, which produces a spectacular ratio against a stop
     much further back — arithmetically fine and practically meaningless.
   - **Reward against risk** is divided out in PHP. A ratio is arithmetic, and arithmetic is
     not a thing to request an opinion about.

   If there is no level on one side, that half of the plan is `null` and the row says so. A
   stop invented to fill the column would be a number nobody could check, and the ranking
   would then put a fabricated ratio above a measured one.

### There is no opportunity score

Rows are ordered lexicographically:

```
tradeable → has a complete plan → confluence → reward:risk → confidence
```

A single weighted score would need coefficients nobody measured, and it would hide which
column a row actually won on. "Four factors, 2.1 to 1" can be disagreed with. "83" cannot.

A large reward ratio is large because the stop is far away. It is therefore below evidence in
the ordering, not above it.

---

## What the model does

It is given the top `ScanAnalyst::SHORTLIST` (6) candidates, already ordered, each with its
direction, confluence, structure and measured plan. It returns a verdict on the scan, a
ranked set of picks, and a note on what it passed over.

It may not:

- **Write a price.** It names candidates by number, and prices are substituted back here from
  the measured plan. A pick naming no candidate is dropped rather than rendered with blanks.
- **Choose the direction.** That came from the EMAs, and the stop and target were measured
  against levels anchored to it. Flipping it would leave a plan whose prices refer to a trade
  nobody proposed.
- **Say how much to stake.** Sizing is [`PositionSizer`](../app/Services/Strategy/PositionSizer.php)'s.

So the worst it can do is prefer a worse real setup, which is an ordering to argue with.

Its per-candidate verdict is `take`, `watch` or `pass`, and an empty pick list is a legitimate
result. Most scans of a quiet market are watch and pass; a scan that always found something
would be one that had learned to fill the field.

**One call for the whole shortlist**, not one per instrument. "Which of these" is a
comparative question, and twenty separate opinions that never saw each other cannot answer
it — they would also cost twenty calls to do it worse.

---

## Caching

The ranking is keyed on the last bar time of every shortlisted instrument, for five minutes.
Any of them printing a new bar is a different scan and gets a fresh answer; none of them doing
so means a reload is not paid for twice.

The focused single-instrument reading is keyed on that instrument's newest bar, as before.

---

## Focused reading

Opening a row switches to [`ChartAnalyst`](../app/Services/Ai/ChartAnalyst.php), unchanged in
what it does: the full level table for that instrument, and a plan in which the model chooses
levels by their number in the list. Opening a row is free; reading it is a model call and
waits to be asked for.

### The timeframe ladder

The brief the model is handed now carries the same instrument read on several timeframes,
from [`TimeframeSummary`](../app/Services/Analysis/TimeframeSummary.php). A model shown one
chart will describe that chart; shown the ladder it can say the thing actually worth saying,
which is whether this timeframe is trading with the regime above it or against it.

Three decisions in there worth not re-litigating:

- **The rungs are derived, not fixed.** They are built around the strategy's own
  `timeframe_trend` and `timeframe_entry`, one step wider for regime and one finer for
  timing. An M1 scalper and an H4 swing trader get ladders that mean something to each,
  rather than both getting D1/H4/H1/M15 because that is what the articles use.
- **A timeframe with no bars is omitted, not called neutral.** "We have not got that chart"
  and "that chart is undecided" are different facts, and rendering the first as a grey pill
  beside three real readings invites somebody to trade an alignment that was never measured.
- **`trend` reuses `StrategyEvaluator`'s own EMA definition.** Three definitions of
  "bullish" in one product is three chances to contradict yourself in front of a customer.

Readings are cached per rung on that rung's newest bar, so a daily reading survives a
five-minute refresh instead of being recomputed with the fast one.

### Breaks of structure

[`Structure::sequence()`](../app/Services/Indicators/Structure.php) labels each confirmed
swing HH / HL / LH / LL and reports where price broke one. The distinction it exists to
draw:

| | |
|---|---|
| **BOS** | A close beyond the most recent swing **in the direction the bias already pointed**. Continuation. |
| **CHoCH** | A close beyond it **against** the prevailing bias. The first evidence a trend has stopped. |

Same arithmetic; what differs is what the market was doing beforehand, which is why a bare
"price broke a level" reading is not worth much.

**A swing is not knowable until `WING` further bars confirm it**, so a break of it can only
be recorded from that bar onwards. Skipping that is lookahead bias: every backtest over the
series would improve, fictionally. There is a test that fails if it creeps back in.

Note that a CHoCH does not by itself flip the bias — the swing sequence is still HH/HL until
the new leg prints a confirmed swing of its own. Character changed; structure has not yet.

### What the chart draws

The focused view renders the candles with overlays the reader controls: structure breaks and
the proposed plan on by default, every measured level off. That last default is deliberate —
on a busy instrument it is a dozen horizontal lines, and the three the plan actually uses
stop being findable among them.

Every overlay is built server-side from numbers this system computed. A browser deriving its
own pivots would eventually disagree with the list the model was shown, and two sets of
levels on one page is worse than none.

### What kind of setup it is

[`SetupClassifier`](../app/Services/Analysis/SetupClassifier.php) measures which of seven
patterns the conditions support — trend continuation, pullback, breakout, breakout and
retest, support/resistance rejection, range, reversal — and the model chooses among the
candidates rather than naming one.

That distinction is the point. Ask a language model *"what kind of setup is this?"* and it
answers with a setup type, because that is what the question wants and the vocabulary is
what it has. It will find a pullback in a range and a reversal in a pullback, fluently, and
state both with the same confidence. So the conditions are arithmetic here and the model's
job becomes choosing — the same arrangement `Structure` already imposes on price levels.

Each type declares its own conditions with weights, and scores the fraction of its own
definition the market actually meets. Below two thirds it is not offered at all: **a pattern
with half its requirements missing is not a weak example of that pattern, it is a different
market wearing the name.** Every candidate carries its `met` and `missing` conditions, so a
support figure never travels without the evidence behind it.

**Nothing wins by default.** A market between levels with no trend, no break and no
rejection matches nothing, `classify()` returns an empty list, and the brief says so
plainly. That is the common case and it is the answer — a classifier that always names a
type is a vocabulary, not a measurement. The stored `setup_type` is nullable for the same
reason, and a null there is a real reading rather than a gap.

Two details worth knowing:

- **A retest is measured against the broken level itself**, not against "somewhere near".
  A retest that is not at the level is just a pullback, and the two differ only in where
  price is now.
- **A range names no direction from the middle.** It offers `buy` at the low and `sell` at
  the high and nothing in between, because naming a side from the middle is how a range
  trade becomes a guess.

### The readings are kept

Every reading is written to `chart_analyses` — see the migration for the full argument. In
short: a reading that lived in a cache for fifteen minutes and then stopped existing made
"was the analyst any good" unanswerable and "what did it say on Tuesday" impossible.

**The refusals are kept as carefully as the plans.** An analyst that declined all week during
a week that went nowhere was right, and there is no way to see that from the trades it did
not cause. A `wait` is stored with null prices rather than dropped, and never with something
plausible in place of them.

One row per bar: asking twice within one bar is the same question, which is already why the
cache key is built that way.

---

## What this is not

Nothing here demonstrates an edge. "Tradeable" means the entry would clear the confluence
floor if it were offered now — a statement about how much is known, not about expected value.
The walk-forward numbers in [`BACKTESTING.md`](BACKTESTING.md) remain the only thing this
project has that speaks to whether any of its ideas make money.
