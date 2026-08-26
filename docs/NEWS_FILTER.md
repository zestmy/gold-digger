# The News Filter

The bot stands aside around scheduled high-impact releases. `bot_settings.news_filter_enabled`
is the switch, `economic_events` is the calendar behind it, and `App\Services\News\NewsBlackout`
is the thing that answers "is now a bad minute to enter".

It refuses entries. It does not close, move or otherwise touch a position that is already open —
a stop that is already in the market is the protection for those.

---

## The bug this exists to fix

`news_filter_enabled` shipped with the settings table. It was defaulted to `true` by
`UserObserver`, it rendered as a toggle on `/settings`, and for a long stretch it **appeared in
no decision path anywhere in the system**. A user could switch it on, save, read it back on, and
be trading straight through Non-Farm Payrolls.

That is worse than not having the feature. A risk control that is absent is a gap someone can
see; a risk control that is present, switched on, and inert is a gap the interface has actively
concealed.

---

## The shape of it

```
  economic_events              bot_settings                  signals
┌──────────────────┐        ┌─────────────────────┐       ┌────────────────────┐
│ scheduled_at     │        │ news_filter_enabled │       │ skip_reason =      │
│ currency         │  ───▶  │ ..._before_minutes  │ ───▶  │  'news_blackout'   │
│ impact           │        │ ..._after_minutes   │       │  or                │
│ actual/forecast  │        └─────────────────────┘       │  'news_data_stale' │
│ fetched_at       │                                      └────────────────────┘
└──────────────────┘
  php artisan news:fetch      InstrumentProfile decides
  hourly, via schedule        which currencies a symbol is exposed to
```

Configuration splits along one line. **Which releases a symbol is exposed to** is a property of
the instrument and comes from `InstrumentProfile` / `config/instruments.php`. **How wide the
window is, and whether it applies at all**, is a risk preference and lives on `bot_settings`
with the rest of them.

| Setting | Where | Default |
|---|---|---|
| Filter on/off | `bot_settings.news_filter_enabled` | on |
| Minutes before | `bot_settings.news_blackout_before_minutes` | 15 |
| Minutes after | `bot_settings.news_blackout_after_minutes` | 15 |
| Which currencies | `InstrumentProfile::for($symbol)['currencies']` | read off the symbol |
| Which impact levels | hardcoded to `high` | — |

An event at `T` blacks out `[T - before, T + after]`. `NewsBlackout::objection()` asks it from
the other side — any event scheduled between `(moment - after)` and `(moment + before)` — which
is the same window expressed from the moment being judged.

---

## Two refusals, not one

`objection()` returns one of two strings, and they are deliberately distinct:

- **`news_blackout`** — a high-impact release for this symbol's currencies falls inside the
  window. The expected case.
- **`news_data_stale`** — the filter is on, but the calendar is missing or older than
  `NewsBlackout::STALE_AFTER_HOURS` (6). It cannot be checked, so the entry is held.

Both are recorded on the signal as `skip_reason`, and `/signals` explains each one in place.
Keeping them apart matters: the first is the filter working, the second is the feed broken, and
collapsing them into one reason would make an outage look like a quiet news week.

### Why stale fails closed

If the calendar has a bad afternoon, the filter reports a blackout rather than waving trades
through. That is the uncomfortable direction and it is the correct one. The setting is a
declared risk control, and a control that quietly stops applying when its data source fails is
the exact failure that gets budgeted for and then isn't there. Gold moves several dollars in
seconds on an NFP print; a stop placed four pips from entry is not a stop during it.

The cost is real and deliberate: if the feed stays down, entries stop. That is visible rather
than silent, and turning `news_filter_enabled` off resumes trading immediately — a decision a
person makes knowingly rather than one a failed HTTP request makes for them.

Six hours is five consecutive hourly failures: past any transient outage and into "this is
broken".

---

## Only high impact

`scopeHighImpact()` restricts the gate to `impact = high`. Medium and low releases are still
fetched, stored and shown — knowing that a quiet afternoon is quiet for a reason is worth
something — but they gate nothing. Blacking out for every medium-impact speech would close most
of the trading day, and there is no evidence here that it would pay for itself.

---

## Currencies come from the instrument, not the name

`currenciesFor()` delegates to `InstrumentProfile` rather than slicing a six-letter symbol in
half. Reading a pair off a name works for FX and metals and fails for everything else: `US30`
has no second leg, so a naive rule returned no currencies and an index never blacked out —
through the US calendar, which is the one thing that moves it hardest.

XAUUSD is exposed to USD releases; gold itself has no calendar. Broker suffixes (`XAUUSDm`,
`XAUUSD.a`) are stripped by the profile, which is why callers pass the strategy's *configured*
symbol rather than the resolved one.

---

## The calendar feed

`php artisan news:fetch` (`FetchEconomicCalendar` → `CalendarFeed`) pulls ForexFactory's weekly
JSON and upserts it into `economic_events`. It runs `hourly()->withoutOverlapping()` from
`routes/console.php`, and the deploy runs it once before traffic returns — without it, every
entry would be held as `news_data_stale` until the next scheduled run.

It needs no API key, which is why it was chosen over Finnhub or TradingEconomics: a risk control
that stops working when a free tier's quota runs out is not one. It is also unofficial and could
change shape without notice, so the failure handling assumes that:

- a failed fetch leaves the previous rows untouched rather than truncating
- a malformed record is skipped, not fatal to the batch
- an empty array is treated as a *failure*, so `fetched_at` never advances on nothing and makes
  stale data look fresh
- HTTP 429 is named separately, because the remedy is "wait", not "investigate"

Events are identified by `sha256(title|currency|scheduled_at)`. Forecast and previous are
deliberately excluded from that key — they are revised in place, and including them would make
a revision look like a new event and double it in the calendar.

`hasPrinted()` keys off `actual` arriving rather than the clock passing: a delayed release is
still ahead of you even once its scheduled minute is behind you.

---

## Who consults it

| Caller | What it does with the answer |
|---|---|
| `SignalGenerator::firstObjection()` | Refuses the entry, records the signal with `skip_reason` |
| `SignalQuality` | Scores "Clear of high-impact news" as one factor among the ambient ones |
| `SignalReviewer` | Same veto applied to copied Telegram signals |
| `NewsCard` | Dashboard countdown to the next release, and whether we are inside a window |
| `AiAnalysisCard` | Explains the current state in words to the model and the reader |

The copier consulting the same object as the executor is the point: a signal someone else posted
is not exempt from this account's risk controls.

---

## What this is not

It is not a volatility filter — it gates on the calendar, not on what price is doing. It does
not know about unscheduled news. And it has never been run against a live feed on a real
account through a major release; the logic is covered by `tests/Feature/News/NewsBlackoutTest.php`,
which is not the same thing.
