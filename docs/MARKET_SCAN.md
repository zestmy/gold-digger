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

---

## What this is not

Nothing here demonstrates an edge. "Tradeable" means the entry would clear the confluence
floor if it were offered now — a statement about how much is known, not about expected value.
The walk-forward numbers in [`BACKTESTING.md`](BACKTESTING.md) remain the only thing this
project has that speaks to whether any of its ideas make money.
