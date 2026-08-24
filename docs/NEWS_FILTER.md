# The News Filter

**Status:** built. Never run against a live feed on a real account.

Standing aside around scheduled economic releases, and the calendar that makes it possible.

---

## 1. The bug this fixes

`bot_settings.news_filter_enabled` has existed since the settings table was created. It is
defaulted to `true` by `UserObserver`, it renders as a toggle on `/settings`, and until this
change it **appeared in no decision path anywhere in the system**. A user could switch it on,
save, read it back on, and be trading straight through Non-Farm Payrolls.

That is worse than not having the feature. A risk control that is absent is a gap someone can
see; a risk control that is present, switched on, and inert is a gap that has been actively
concealed by the interface.

---

## 2. What it does

A setup is refused when the bar it was found on overlaps the blackout window of a scheduled
release the filter has been configured to care about. The signal is still recorded, with
`skip_reason = news_blackout` and the release named in `features.news_event` — the same
treatment every other filter gets, for the same reason: *"why did the bot not trade that"* has
to be answerable from the row.

```
   market_events                 bot_settings                    signals
 ┌────────────────┐          ┌────────────────────┐         ┌──────────────────┐
 │ scheduled_at   │          │ news_filter_enabled│         │ skip_reason =    │
 │ currency       │  ───▶    │ ..._before_minutes │  ───▶   │  'news_blackout' │
 │ impact         │          │ ..._after_minutes  │         │ features.        │
 │ title          │          └────────────────────┘         │  news_event      │
 └────────────────┘                                         └──────────────────┘
   php artisan            config/trading.php `news`
   news:import            decides which events count
```

Configuration splits along one line: **what counts as an event** is a property of the
instrument and lives in `config/trading.php`; **how wide the window is, and whether it applies
at all**, is a risk preference and lives on `bot_settings` with the rest of them.

| Setting | Where | Default |
|---|---|---|
| Filter on/off | `bot_settings.news_filter_enabled` | on |
| Minutes before | `bot_settings.news_blackout_before_minutes` | 15 |
| Minutes after | `bot_settings.news_blackout_after_minutes` | 15 |
| Currencies that matter | `config('trading.news.currencies')` | `USD` |
| Impact levels that matter | `config('trading.news.impacts')` | `high` |

`USD` because gold is priced in dollars — a euro-area release moves XAUEUR, not XAUUSD.
`high` only because medium-impact releases run to several a day, and including them turns a
filter into a curfew.

---

## 3. Three decisions worth understanding

### 3.1 It is compared against the whole bar, not the bar's open

`TradingSession` tests a bar's open time, and that is fine — a session is hours wide, so an
hour either way does not change which session a bar belongs to.

A blackout is minutes wide, and the entry does not happen at the bar's open. It happens after
the bar *closes*, when the strategy sees it. Testing only the open leaves a hole one timeframe
deep on the near side of every release:

```
   bar opens 13:00 ──────────────── bar closes 13:05, entry placed here
                          ▲
                          └── blackout opens 13:04 for a 13:19 release
```

The open is outside the window; the fill is inside it. So the bar is treated as the interval it
covers, and any overlap is enough. `test_an_event_landing_inside_the_bar_blocks_it` pins this.

### 3.2 It fails open, and the health monitor is what makes that safe

An empty calendar blocks nothing.

The alternative — treating "no events known" as "assume the worst" — means a failed import
silently halts trading, and a bot that stops for reasons its owner cannot see is worse than one
that trades through a release. So the filter degrades to exactly the behaviour that existed
before it.

That is only defensible because it is said out loud. `HealthMonitor` raises
**`news_calendar_stale`** when the filter is on, the window is non-zero, and the calendar does
not reach at least `NEWS_CALENDAR_STALE_HOURS` (12) into the future. This is the same bargain
`queue_stalled` makes for `trading.queue_evaluation`: the escape hatch is safe to offer only
because something notices when it is being used.

Staleness is measured on the calendar's **horizon** — how far ahead it reaches — not on when a
row was last written. An importer that keeps rewriting last week looks perfectly healthy by any
other measure and is protecting nothing.

### 3.3 It is arithmetic, and deliberately not AI

[`AI_INTEGRATION.md`](AI_INTEGRATION.md) lists this feature under the AI plan and then argues it
back out again. The rule has to replay identically inside `php artisan backtest`, and a language
model cannot do that: its training data contains the future of any bar it is asked about, so a
backtested model verdict is contaminated by construction and flatters itself. A comparison
between two timestamps has no such problem.

`Backtester::objection()` applies the same check, in the same position in the filter order, as
`SignalGenerator::firstObjection()`. That ordering is not cosmetic — a backtest that applies
filters in a different order attributes the same skipped setup to a different reason, and then
the skip histogram it prints describes a system nobody is running.

**This is what makes the 15-minute default a starting point rather than a claim.** Widen it,
narrow it, add `medium` to the impacts, and measure the result instead of arguing about it.

---

## 4. The calendar

```bash
php artisan news:import
```

Scheduled hourly in `routes/console.php`. Reads the free ForexFactory weekly JSON feed — no
key, no account, one request — and upserts into `market_events`.

**Hourly, for a feed that publishes a week at a time**, because release times get revised and
the feed does not announce it. Importing weekly means trading a stale copy for six days.

**An empty fetch changes nothing.** A source that returns no rows is "no new information",
never "the week is empty". Wiping the calendar on a failed request would disable the filter at
the moment nobody would think to check — and since the filter fails open, a wiped calendar
trades straight through the release it was meant to avoid.

**Upsert on (currency, title, scheduled_at).** The feed publishes no stable id, and a currency
does not release two events of the same name at the same instant. A revised *time* arrives as a
new row and the old one is left alone: a stale extra blackout costs a few skipped setups, where
deleting the wrong row costs a trade taken into a release.

**History is kept for 90 days** (`--prune-days`). A backtest over last month has to see the
blackouts that were in force during it, or it measures a different system from the one that
traded.

### Swapping the source

Free calendar feeds disappear, change shape, and rate limit. `CalendarSource` is bound in
`AppServiceProvider`, so replacing one is a binding change rather than surgery on the importer —
which matters on the day it breaks. `market_events.source` records what wrote each row, and
`manual` is a first-class value: a row typed in by hand blacks out its window exactly like an
imported one, which is the fallback when the feed is down and something big is known to be
coming.

---

## 5. Operating it

**Turning it off** is the `news_filter_enabled` toggle, and now it means something. Setting
both window widths to zero also disables it — a filter switched on and configured to nothing
blocks nothing, rather than blocking the instant of the release.

**Checking it is working:**

```bash
php artisan news:import
```

The command prints how far ahead the calendar now runs. Then watch `/signals` for
`news_blackout` rows around the next high-impact USD release; each carries the release name in
`features.news_event`.

**If nothing is ever blocked**, in order of likelihood: the calendar is empty (the monitor
should already be saying so), the impact filter is set tighter than the week's releases, or the
window is too narrow to overlap any bar.

---

## 6. What is not built

- **No `actual`-figure reaction.** The column is imported and stored; nothing reads it. A rule
  like "stay out longer when the number missed forecast badly" is possible and untested.
- **Nothing is re-entered after the window.** The bot simply resumes taking setups as they
  appear. A setup that was refused during the blackout is not revisited.
- **Holidays are stored, not used.** `impact = holiday` rows are imported because a thin
  market is worth knowing about, but `config('trading.news.impacts')` does not include them by
  default and low liquidity is not the same risk as a scheduled print.
- **One calendar, all users.** Correct today — CPI happens at the same instant for everyone —
  and it stays correct if this ever becomes multi-tenant, since only the window widths are
  per-user.
