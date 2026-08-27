# Market Data — where a run of bars comes from

Written when `candles` reached 91% of the database and the reason turned out to be one
consumer. Read [`SIGNAL_GENERATION.md`](SIGNAL_GENERATION.md) for what the bars are used
for, and DEPLOYMENT.md's *Retention* section for what is kept.

---

## The measurement that produced this

| Consumer | Bars it asks for |
|---|---|
| `StrategyEvaluator` — trading | 300 |
| `PriceChartCard` — dashboard chart | 300 |
| `TimeframeSummary` — the ladder | 260 per rung |
| `ChartAnalyst` — chart reading | 120 |
| **`StrategyImprovement` — walk-forward** | **20,000** |

One consumer wanted two orders of magnitude more history than everything else combined.
And it is the one that runs on a person's deliberate act rather than on every closed bar.

Storing history deep enough for it put **80,000 bars against ten trades** — 19.1 MB of a
21 MB database — and `candles` is stored *per broker account* rather than shared, so it
multiplies by tenant rather than being amortised across them.

---

## The line, and why it is not a setting

```
MarketData::forTrading()   →  broker bars, always
MarketData::forAnalysis()  →  vendor when configured, broker otherwise
MarketData::forBacktest()  →  stored when deep enough, vendor when not
```

`forTrading()` has no setting, no argument and no fallback that returns a vendor's bars.
That is deliberate. The `candles` migration made the argument first and it has not got any
weaker:

> Indicators decide *where the stop goes*. If ATR is computed from one vendor's gold series
> and the order is filled against the broker's, the stop is sized from prices the broker
> never quoted.

A switch that could point the stop calculation at a third party is a switch somebody
eventually flips at three in the morning. So there isn't one.

**Everything that decides a price still reads the terminal's own bars**: `MarketContext`,
`SignalGenerator`, `TradeManager`, `SignalQuality`, `SignalSeries`, `AutonomousTrader`,
`MarketScanner`. None of them changed.

---

## What a vendor is actually for

Deep history for a replay, fetched when asked and **never written down**. That is the whole
mechanism: a year of H1 is 6,000 bars, reading it to answer one question costs nothing on
disk, and storing it cost 91% of the database.

There is a test asserting vendor bars never reach the table — if they did, this would be a
more elaborate way of causing the problem it exists to solve.

Two smaller rules in `forBacktest()`, both about not making a replay worse:

- **Stored bars win when there are enough of them.** They are this broker's own prices,
  which is a better replay for the same reason they are a better basis for a stop.
- **A shallower vendor answer is refused.** Swapping this broker's prices for a stranger's
  in order to get *fewer* bars would be worse by both measures.

With no vendor configured, a backtest gets what is stored and is told how much that is —
honestly short rather than padded.

---

## Configuring one

Off unless `MARKETDATA_KEY` is set, the same way the AI and the alert channel are.

```env
MARKETDATA_KEY=
MARKETDATA_BASE_URL=https://api.twelvedata.com
```

`config/marketdata.php` also holds the symbol map. This is the fragile part of talking to a
vendor: brokers publish `XAUUSDm`, `XAUUSD.a`, `GOLD`, and vendors want `XAU/USD`. Matching
is on the longest configured prefix, because the suffix varies and the instrument does not.

**An unmapped symbol fetches nothing rather than being guessed at.** A chart of the wrong
instrument looks like an answer, which is worse than an empty one.

### Adding another vendor

Implement `SeriesProvider` — three methods — and point `MARKETDATA_PROVIDER` at the class.
`BrokerSeries` and `TwelveDataSeries` are both roughly a page. Providers return **unsaved
`Candle` instances**, so every indicator already reads them and nothing downstream learns a
second representation.

---

## What this changed about retention

`RETAIN_CANDLE_BARS` dropped from 30,000 to **3,000** per series, because the 20,000-bar
consumer no longer needs stored history. 3,000 is ten times the deepest remaining reader.

If you run **without** a vendor, a long walk-forward is limited to what is stored — so raise
that number instead. Roughly 240 bytes a bar per series.

---

## What this is not

It is not a real-time feed, and it is not a second opinion on price. Nothing subscribes,
nothing streams, and no vendor bar has ever been compared against a broker bar to see how
far apart they are. That comparison would be worth doing before trusting a vendor replay
very far — it is the obvious next measurement and it has not been made.
