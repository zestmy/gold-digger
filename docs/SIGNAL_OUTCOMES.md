# Signal Outcomes

What became of every signal, traded or not, measured from the bars that followed it.

---

## Why this exists

Until this was built the only outcomes on record were the trades that were actually
placed - seven in a month on the account it was built on. Every signal the strategy
declined, every copied signal the reviewer turned down, and every signal somebody read
off the card and traded by hand left no trace of whether it would have worked. So:

- the win rate could not be measured, only the win rate of the few trades taken;
- the confidence score was a count of factors that agreed, never checked against what
  happened next;
- every argument about a filter - "ADX 25 is too strict", "the targets are too far" -
  was an argument about an opinion.

A signal is a claim about price: from here, this level before that one. That claim can be
scored from bars alone, for every signal ever recorded, whether or not anybody traded it.
That turns two dozen signals a month into a dataset, and it is the prerequisite for every
improvement to the strategy - none of them can be measured without it.

---

## What is recorded

One row in `signal_outcomes` per signal, keyed to the signal it belongs to (`App\Models\Signal`
for the strategy's own, `App\Models\TelegramSignal` for a parsed copied one):

| Field | Meaning |
|---|---|
| `reference_price`, `stop_price`, `tp1..3_price` | The levels the signal named. `risk` is `|reference - stop|`. |
| `started_at` | The first bar counted: the bar after the signal bar for the strategy's signals, the first bar opening at or after the post for a copied one |
| `mfe_r`, `mae_r` | The best and worst the price did against the reference, in R, over the bars seen |
| `tp1_bars`, `tp2_bars`, `tp3_bars`, `sl_bars` | How many bars until each level was reached; null if it never was |
| `first_hit` | `tp1` or `sl` - whichever was reached first |
| `r_at_1`, `r_at_5`, `r_at_20` | Where the close sat after that many bars, in R |
| `status` | `open`, `won`, `lost`, `expired` |
| `context` | Confidence band, session, hour, instrument and a few readings, copied at the time |

**The rules, stated so they can be argued with.**

- A level counts as touched when a bar's range reaches it. Nothing is interpolated inside
  a bar.
- When one bar reaches both the stop and a target, the stop is taken to have come first.
  The order inside a bar is unknowable from bars, and the pessimistic reading is the one
  that cannot flatter a strategy.
- `won` and `lost` are decided by whichever of TP1 and the stop is reached first. Later
  targets are recorded for what they are worth; they do not change the verdict.
- Tracking continues to the stop, the final target, or the horizon (`OUTCOME_HORIZON_BARS`,
  100 bars of the signal's timeframe by default), so excursions and later rungs are
  measured even after the verdict is in. A signal that reaches neither level within the
  horizon is `expired`.
- A copied signal names no timeframe, so it is measured on `OUTCOME_COPIED_TIMEFRAME` (M5).
  Its instrument is resolved to the broker's own name, because that is the name the bars
  are stored under. Where the provider named no entry, the last close stored at the post
  time is the reference - where a market order would have filled.
- **A copied signal that names an entry is a pending order.** Nothing is scored until a
  bar's range reaches that entry (or zone); the bars before are counted as `wait_bars`,
  and a signal the market never comes back to within the horizon is `unfilled` - neither
  a win nor a loss, but reported, because a provider whose entries rarely fill is one
  whose published results were mostly never available. The first day's figures, scored
  from the post itself, credited a sell posted at 4,600 with price at 4,550 an instant
  target and a favourable "worst" excursion; the average worst excursion came out above
  zero, which no correctly scored signal can produce. That is the bug this rule fixed.

`won` is a statement about the levels the signal published, not about any position. A
trade managed with a break-even stop can lose on a `won` signal and vice versa. It is the
honest unit for judging the signal itself, which is the thing being measured here; what
the account made is on the Trades page.

---

## How it runs

Two triggers, neither depending on the other:

- **The candle push.** When the terminal delivers a genuinely new bar for a series,
  `CandleController` advances every unresolved outcome on that series before anything
  else happens - inline, even when evaluation is queued, because it is a handful of reads
  and writes and must never depend on a worker being up.
- **The schedule.** `signals:track` runs every five minutes: it opens tracking for any
  signal that has none (back to `OUTCOME_BACKFILL_DAYS`) and walks every unresolved row.
  A signal written while nothing was pushing - a copied one at the weekend - is still
  scored once bars arrive.

Both are idempotent: a bar already folded in is never counted twice, and a row is
resolved once.

```bash
php artisan signals:track                      # open pending, advance everything
php artisan signals:track --backfill-days=90   # reach further back once, for history
```

The bars have to exist. `data:prune` keeps a fixed number of bars per series, so a signal
older than that window cannot be scored and stays `open` with no bars seen. That is
recorded rather than guessed around.

---

## Where it shows

- **Trades → Performance** carries a "Signal outcomes" section for the period: tracked,
  win rate (first target before stop), expectancy in R on the first target, average best
  and worst excursion, bars to TP1 and to the stop, and the same figures by source, by
  confidence band, by session and by instrument. Every group shows its `n`; under thirty
  decided signals the section says so, because a rate over ten signals is not a finding.
- **The Signals feed** shows each signal's outcome under its levels once it has one:
  "TP1 in 3 bars", "Stopped in 2 bars", "Neither level in 100 bars".

---

## What it is for

In order of what it unlocks:

1. **A win rate with a sample size.** Per source, per confidence band, per session, per
   instrument, per hour. The number that decides whether "profitable" may be said.
2. **Calibrating the confidence score.** `SignalQuality` weights are hand-set. With
   outcomes, each factor's weight can be fitted to the realised win rate of the signals
   that carried it - the score becomes a measurement.
3. **Judging providers on their signals, not on the trades taken.** A channel's win rate
   today counts only what the copier executed. Outcomes score every parsed post.
4. **Measuring a change.** Every proposal - a pullback entry, R-based targets, a different
   ADX floor, a session filter - can be judged on what the signals under the new rule do,
   before any of them is traded.

---

## Not built

- **Calibration itself.** Outcomes are recorded; nothing yet reads them back into
  `SignalQuality` weights or the ADX threshold. That is deliberate: the data comes first.
- **Cross-tenant aggregation.** Stats are per account. A platform-wide view of provider
  outcomes - the leaderboard - would read the same table across tenants and is a
  separate decision about what one subscriber may learn from another's feed.
- **Per-bar path storage.** Only the summary of the walk is kept, not every bar's R. If
  a later analysis needs the path, the bars are still in `candles` within the retention
  window and the walk can be re-run.
