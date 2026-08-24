# AI Integration via OpenRouter — Audit and Plan

**Status:** analysis / proposal. Nothing here is built.
**Context:** the system has no AI in it today, but the schema was designed as if it would.

---

## 1. What is already here

**Nothing AI-shaped is installed.** `composer.json` has no LLM SDK, `.env.example` has no key,
`config/services.php` has no entry. That is the good news: there is no half-integration to unpick.
`Illuminate\Support\Facades\Http` is already in use (`AlertNotifier`), and that is the whole of
what an OpenRouter client needs.

**Four places in the schema were reserved for this and never filled.**

| Reservation | State today |
|---|---|
| `signals.confidence_score` — *"Strategy confidence (0.0–1.0). Future: use for position sizing or filtering"* | Written as literal `null` at `SignalGenerator.php:143`. Never read. |
| `signals.features` — *"JSON object with indicator values at signal time (for ML and analysis)"* | **Populated.** EMA/ADX/ATR plus `sl_pips`, `order_tp_pips`, `sessions_open`, `balance`, `pip_size`. |
| `trades.notes` — free-form trade review | Written only by `PositionReconciler`, to record that a position was adopted from the terminal. Nothing writes a review. |
| `trade_screenshots` + `bot_settings.capture_screenshots` — *"Future: AI analysis of chart patterns"* (`ARCHITECTURE.md`) | Table, model, relation and settings toggle all exist. **Nothing ever captures a screenshot.** A dead limb. |

`signals.features` is the significant one. Every setup gets a row — *including the rejected ones,
with `skip_reason`* — so the system has been quietly accumulating a labelled dataset since signal
generation went in. One feature vector per bar that produced a setup, with an outcome attached via
`resulting_trade_id` when it traded. Nothing reads it.

**`news_filter_enabled` was a switch wired to nothing.** ~~It is on `bot_settings`, defaulted to
`true` by `UserObserver`, and rendered as a toggle in `Livewire/Pages/Settings.php`. It does not
appear in `SignalGenerator::firstObjection()` or anywhere else in the decision path. A user who
sees it switched on believes the bot stands aside for NFP. It does not.~~

**Fixed.** The audit finding above stood at the time of writing; F5 (a) and (b) have since been
built and the switch now does what it says. See [`NEWS_FILTER.md`](NEWS_FILTER.md). The finding is
left in place rather than deleted because it is the clearest example of what this audit was for:
the most valuable thing on the AI feature list turned out to be a piece of arithmetic and a
missing data feed.

**The decision path has one clean choke point.** `firstObjection()` returns the first reason a
setup was refused, as a string, and the signal is recorded either way. Any new veto — AI or
otherwise — is one more early return, and is immediately visible on `/signals`, countable in SQL,
and comparable against the setups that were let through. It is hard to overstate how much easier
this makes evaluating an advisory model honestly.

---

## 2. Three constraints that decide the whole shape of this

### 2.1 An LLM cannot be inside the candle push

`config/trading.php` defaults `queue_evaluation` to off, so `CandleController` evaluates strategies
**inside the EA's request** — and `WebRequest` blocks the terminal's event thread for the duration.
That is fine for a few hundred bars of arithmetic. A model call is seconds, and seconds of stalled
event thread on every closed bar is not acceptable at any level of usefulness.

**Every AI call is queued or scheduled. None runs in a request the terminal is waiting on.**
`EvaluateNewBars` and `trading.queue_evaluation` are the existing pattern for exactly this.

### 2.2 An LLM opinion cannot be backtested — ever

This is the load-bearing constraint and it is not obvious.

`README.md` states the standard: *"a change can be measured instead of argued about."*
`php artisan backtest` replays a change over stored bars using the same evaluator that trades.
An AI filter cannot meet that standard, for a reason no amount of engineering fixes:

**The model's training data contains the future, relative to any historical bar.** Ask a model
"was this a good gold setup on 2024-03-12" and it answers already knowing what gold did on
2024-03-13. Every backtest of an LLM verdict on historical data is contaminated by construction,
and it will look brilliant. That is lookahead bias in its purest form, delivered by a component
that cannot be made to forget.

Three consequences, all non-negotiable:

1. **Forward shadow-mode is the only valid test of an AI trading opinion.** Record the verdict, do
   not act on it, wait for real bars, then measure. Months, not an afternoon.
2. **Any verdict that ever does touch trading must be persisted against the bar it applied to, and
   the backtester must read the stored verdict — never call the API.** That keeps replays
   deterministic and free, and it keeps parameter sweeps from costing hundreds of dollars.
3. **A model must never be asked to reason about a date.** Prompts carry the feature vector and the
   current context; they do not carry "this is March 2024".

### 2.3 An AI outage must be invisible to trading

`AlertNotifier` already sets the doctrine: *"If Telegram is down… that must not stop the sweep from
recording the incident… a notification outage becomes a monitoring outage, and the second is much
worse than the first."* The same applies with more force here. A model that times out, rate limits,
returns malformed JSON, or has an unpaid invoice must degrade to **the system's behaviour before
the feature existed**, silently and immediately. An AI feature that can halt the bot is a net
reduction in reliability no matter how good its output is.

---

## 3. Foundation — build this before any feature

Nothing in §4 is worth starting until this exists, and it is perhaps two days of work.

**`config/ai.php`**, in the style of `config/alerts.php`: `OPENROUTER_API_KEY`, a **model per task**
(triage is not the review), per-task timeout, and a `configured()` guard so an absent key means
*feature off*, not *exception*. Choosing a model per task is the actual reason to use OpenRouter
rather than a single vendor: one key, a cheap small model for log triage, a stronger one for the
weekly review, and the ability to swap either without touching code.

**`App\Services\AI\OpenRouter`** on `Http::` — `POST https://openrouter.ai/api/v1/chat/completions`,
bearer auth, plus the `HTTP-Referer` / `X-Title` headers OpenRouter uses for attribution. Ask for
structured JSON output and validate it against a schema on the way back; a free-text answer that
must be regex-parsed is a bug waiting for a slow news week.

**An `ai_requests` table.** Task, model, prompt hash, tokens in/out, cost, latency, status, the raw
response, and a nullable morph to the row it concerns. This is not optional bookkeeping — it is
what makes shadow mode measurable, what makes spend visible before the bill arrives, and what lets
a bad verdict be traced back to the prompt that produced it.

**A daily spend cap**, enforced before the call, not after. Breach switches the feature off and
raises an `Alert` through the existing `HealthMonitor` / `AlertNotifier` path. Cost overrun on a
personal project is a real failure mode and the cheapest possible thing to prevent.

**A dedicated `ai` queue**, and a `fake` driver returning canned JSON so the test suite never
touches the network. `Http::fake()` for everything else. The repo's convention is that every
subsystem ships tests; this one needs them more than most, because the dependency is
non-deterministic by nature.

---

## 4. Features, ranked by blast radius

Three tiers. **Nothing is promoted a tier without evidence from the tier below it.**

### Tier 1 — the model writes prose for a human. Ship these.

The model can be wrong and the cost is a confusing paragraph. All of it runs on data the system
already holds and nobody currently reads.

**F1. Daily and weekly trading review.** Feed `daily_summaries`, settled trades, and the
`skip_reason` distribution for the period; get back a written review. The itemised cost columns —
`entry_spread_pips`, `commission_money`, `swap_money`, `slippage_pips` — exist precisely so the
question *"which cost is eating the edge"* can be asked, and nothing currently asks it. Delivered
through `AlertNotifier` to Telegram and surfaced on `/analytics`. One call a day. Pennies.

**F2. Incident and retcode explainer.** `bot_logs` plus open `alerts` into plain English, attached
to the alert body. Critically: **feed the retcode→remedy mapping in the prompt** — it already
exists in `GDExecutor.mqh` and `mt5_executor.py` — so the model grounds on the repo's own reference
instead of recalling MT5 documentation approximately. A model that invents a remedy for `10016` at
3am is worse than no explanation at all.

**F3. Sweep and walk-forward narration.** `SweepRunner` and `WalkForward` already produce the
numbers; the useful and tedious part is noticing that three parameter sets are the same trade, or
that in-sample and walk-forward disagree in the way overfitting disagrees. `WalkForwardReport` has
everything needed. The model narrates; it does not select parameters.

### Tier 2 — the model has an opinion, recorded, acted on by nothing.

**F4. Fill `signals.confidence_score` in shadow mode.** Queued after the bar closes: the `features`
JSON plus the outcome distribution of similar historical setups, in; a 0–1 score and a one-line
rationale, out. Score to `confidence_score`, rationale to `features['ai_rationale']`. **Nothing
reads it.** After enough settled trades, ask the only question that matters: do high-confidence
signals actually outperform low-confidence ones?

Expect the answer to be no, and plan for that being fine. A language model is a poor calibrated
probability estimator over a vector of numbers — a logistic regression on the `features` column
already being collected would likely beat it, costs nothing, and is deterministic enough to
backtest properly. The model's genuine advantage is *unstructured* context, which is F5's
territory, not this one. F4 is worth running because it is cheap and the column was reserved for
it, but it should be framed as a test that may well come back negative.

**F5. The news filter — mostly not an AI feature. (a) and (b) are now BUILT — see
[`NEWS_FILTER.md`](NEWS_FILTER.md).** The most valuable item in this document is this one, and
the model does almost none of the work:

- **(a) Data.** A scheduled job pulls an economic calendar into a `market_events` table — time,
  currency, impact, name. Gold trades against USD, so CPI, NFP and FOMC are the ones that matter.
  This is a feed, not a model.
- **(b) The filter.** Pure arithmetic in `firstObjection()`: within N minutes of a high-impact USD
  event and `news_filter_enabled` is on → `skip_reason` `news_blackout`. **Deliberately no LLM.**
  It is deterministic, it replays exactly in a backtest, and it makes the settings toggle honest.
- **(c) Only then, optionally, AI.** Summarising the week ahead for the Telegram digest, or
  classifying an ambiguous event name. Advisory. Not in the decision path.

Doing (a) and (b) is the single highest-value change on this list. It is also the one place where
the right answer to "should this use AI" is no.

**F6. Chart screenshots — capture them, do not analyse them.** `trade_screenshots` cannot be used
by anything until the EA actually calls `ChartScreenShot()` and uploads. That capture is worth
building, for human trade review. Pointing a vision model at the result is not: the model would be
reading a picture of data the system already holds numerically, and more precisely, in `candles`.

### Tier 3 — the model touches money. Gated on Tier 2 evidence.

**F7. Confidence-scaled sizing.** Only if F4 demonstrates calibration over a meaningful number of
settled trades. Then: a bounded multiplier on the risk-sized lot — 0.5× to 1.0×, **never above
1.0×**, so the worst case is under-trading a good setup rather than over-risking a bad one. The
verdict is stored per signal and the backtester replays the stored value.

**F8. Natural-language control ("close everything, cut risk to 0.5%").** Demos well. It is an LLM
with a hand on `trade_commands`. If it is ever built, the command lands as `pending` and requires a
human click — at which point the existing `QuickActionsCard` buttons were faster. **Recommend not
building this.**

---

## 5. Phasing

| Phase | Contents | Gate to the next |
|---|---|---|
| A | §3 foundation: config, client, `ai_requests`, spend cap, fake driver, tests | The cost of a call is visible before it is spent |
| B | F1, F2, F3 | The daily review says something a glance at `/analytics` would not |
| C | F5 (a) and (b) — the real news filter, no AI ✓ **done** | `news_blackout` appears in `skip_reason` and backtests replay it |
| D | F4 shadow mode, plus a logistic-regression baseline on `signals.features` | Months of forward data, then: does the score separate winners from losers, and does it beat the regression? |
| E | F7, only if D came back positive | — |

A, B and C are worth doing regardless of what D shows. D is a genuine experiment with a genuine
chance of a negative result, and the design above is arranged so that a negative result costs
nothing but the API spend.

---

## 6. Decisions worth not re-litigating

| Decision | Reason |
|---|---|
| Model calls are queued, never in a request the EA is waiting on | `WebRequest` blocks the terminal's event thread. See §2.1. |
| No AI verdict is ever backtested against history | The model's training data contains the bar's future. See §2.2. |
| Any acting verdict is persisted per bar; backtests read the row, never the API | Determinism, and a parameter sweep that calls an API is a sweep that costs money. |
| An AI failure degrades to pre-feature behaviour, silently | `AlertNotifier`'s doctrine, applied where the stakes are higher. |
| The news filter is arithmetic over a calendar feed, not a language model | It has to replay exactly in a backtest, and a deterministic rule does. |
| Model chosen per task in config, not hardcoded | It is the reason to be on OpenRouter at all. |
| Structured JSON output, schema-validated | Parsing prose from a model that had a bad day is a failure mode with no upside. |
