# Monitoring and Alerts

How a dead bot reaches you.

The dashboard has always been able to show that the executor is offline or blocked. But a
dashboard only helps somebody who is looking at it, and the point of an unattended bot is that
nobody is. A silently dead executor holding open positions is the risk the handoff named
first — this is what addresses it.

---

## What is watched

| Condition | Level | Clears when |
|---|---|---|
| `executor_missing` | critical with positions open, else warning | Any heartbeat arrives |
| `executor_offline` | critical with positions open, else warning | A heartbeat arrives within `STALE_AFTER_SECONDS` |
| `algo_trading_disabled` | critical | The terminal reports Algo Trading on |
| `broker_disconnected` | critical | The terminal reports the broker connected |
| `feed_stalled:{timeframe}` | critical with positions open, else warning | A bar arrives within three bar-lengths |
| `daily_loss_limit` | critical | Realised losses fall back inside the limit — in practice, tomorrow |
| `queue_stalled` | critical when queued evaluation is on, else warning | A worker starts draining the queue |

Every condition has an explicit clear rule. That is not decoration: an alert that never resolves
teaches you to ignore the channel it arrives on, and then the channel is worse than nothing.

### Why a stalled queue is critical

Only relevant with `trading.queue_evaluation` on — and it is the condition that makes that
switch safe to offer. With evaluation queued, a candle push stores its bars and hands the
thinking to a worker. A worker that is not running produces no error anywhere: the executor
heartbeats, the feed flows, bars accumulate, and the bot simply stops trading while everything
on the dashboard looks healthy.

Measured on the age of the oldest unclaimed job rather than the depth of the queue — a hundred
jobs drained promptly is a busy system; one job sitting for an hour is a dead one.

### Why the feed gets its own alert

An executor can heartbeat perfectly while its candle push fails — a whitelist entry that covers
one URL and not the other, or a symbol whose history will not load. From the dashboard that is
indistinguishable from a strategy that has simply seen no setups: no signals, no explanation.

It escalates to **critical** while positions are open, for the same reason `executor_offline`
does. The take-profit ladder, the reversal exit and the trail are all read off bars; with none
arriving, an open position is protected by its broker-side stop and nothing else. The
scheduled `trades:manage` pass (below) does not change that — it re-reads the same stale
series every minute and finds nothing new. With nothing open, a stalled feed costs
opportunity rather than capital, and stays a warning.

### The management pass runs on a schedule too

`trades:manage` is scheduled every minute, `withoutOverlapping`, beside `bot:monitor`. Trade
management normally runs from the candle push, and that stays the trigger; the schedule is
the correction for a push that has stopped, so that a queued close or stop move the executor
never got to claim is re-issued, and one the broker refused is retried, without waiting for a
bar that may not come. It is safe to run without a new bar because every action carries a
fixed idempotency key — see `TRADE_MANAGEMENT.md`.

---

## When silence is correct

**A bot switched off on purpose is not a fault.** Nothing fires for a stopped bot — unless it is
still holding positions.

That exception is the whole point of the design. A dead executor with positions on the book is
what you actually need waking up for, and it is exactly the case a naive "is it running" check
misses, because the owner turned it off deliberately and forgot what it was still carrying.

---

## An alert is an incident, not a message

The check runs every minute. If it sent a message each time it found something, a condition true
for an afternoon would produce a few hundred.

So each condition opens a row in `alerts` that lives until it clears:

- **Once when it starts.** Then at most once an hour while it persists — a single message on day
  one is easy to miss.
- **Once when it clears**, but only if the alert was announced in the first place. An incident
  nobody heard about does not need an all-clear.
- **A recurrence starts a new row.** The history is a list of incidents rather than one row that
  flaps, so "how often does this happen" has an answer.

Delivery failure is never allowed to fail the sweep. If Telegram is down, the incident is still
recorded and resolutions still happen — `notified_at` stays null and the next run retries.
A notification outage must not become a monitoring outage.

---

## Setting up Telegram

Off unless both values are set. Unconfigured is not an error: incidents are still recorded and
visible on `/settings/activity`, they simply reach nobody.

1. Message [@BotFather](https://t.me/BotFather), `/newbot`, and keep the token.
2. Send your new bot any message.
3. Read your chat id from `https://api.telegram.org/bot<TOKEN>/getUpdates`.

```env
TELEGRAM_BOT_TOKEN=123456:ABC-DEF...
TELEGRAM_CHAT_ID=987654321
```

Alert text goes to Telegram's servers. The messages carry balances, symbols and P&L figures —
nothing that identifies an account to a broker, but not nothing either.

---

## Running it

```bash
php artisan bot:monitor              # evaluate, record, and send
php artisan bot:monitor --quiet-channel   # evaluate and record, send nothing
```

Scheduled every minute in `routes/console.php`, which **requires cron on the server**:

```
* * * * * cd /var/www/gold-digger && php artisan schedule:run >> /dev/null 2>&1
```

Without that line nothing here runs, and there is no warning that it is missing — the alerts
simply never arrive, which is the failure mode this feature exists to prevent. Check
`storage/logs` or run the command by hand once after deploying to confirm.

`withoutOverlapping` is what lets `HealthMonitor` keep "one open incident per key" in
application code rather than a unique index, since MySQL treats NULLs as distinct and cannot
express "unique among unresolved rows".

The `queue_stalled` remedy names supervisor — `sudo supervisorctl restart gold-digger-worker:*`
— because that is what `scripts/server-setup.sh` installs; there is no systemd unit for the
worker. The worker must drain `--queue=strategy,default`: strategy evaluation goes onto the
`strategy` queue, and a worker on the default queue alone is exactly the silent failure this
alert exists for. See `DEPLOYMENT.md`.

`bot_heartbeats` is keyed per executor (user + broker account + source, see
`MT5_EA_BRIDGE.md`), and the monitor watches each account on its own: the offline, blocked
and stalled-feed checks run against the newest row for every account the user has, with
that account's own open positions deciding whether an outage is critical. Reading only the
user's newest heartbeat, as it used to, reported on whichever terminal was fine - the one
that had gone quiet was invisible precisely because the other kept polling.

Incident keys are unchanged for the one-account case, which is nearly every account, and
gain an account suffix (`executor_offline:7`) only when the user has more than one, so an
incident opened before a second terminal existed is the same incident after. The daily
loss limit stays account-wide: it is a share of a balance, and the balance read is the
newest one reported.

---

## One tenant cannot stop the sweep

`bot:monitor`, `copier:protect` and `ai:decide` each iterate every account. They used to do
it in a plain `foreach`, which meant a throw for one tenant aborted the command and every
account with a higher id was skipped — silently, and for as long as the cause persisted,
which for anything deterministic is for ever.

That was a correctness fault at any size, not a scaling one. Two tenants in it is invisible;
it still meant one customer's malformed symbol spec could stop everybody else's stops being
trailed.

`TenantSweep` runs each account inside `Tenant::for()` and catches what it throws. The
failure becomes an incident on `/settings/activity` filed against the tenant it happened for, the sweep
continues, and the command reports how many accounts it could not finish — because a
scheduled run nobody watches is exactly where a partial sweep would otherwise pass for a
complete one.

The monitor most of all: it is the thing that notices when something has stopped working, so
it must not be the thing that stops working.

> **Not done.** The sweeps are still serial, so a slow account still delays the ones behind
> it and a long enough tick still meets `withoutOverlapping`. Moving per-tenant work onto the
> queue is the fix for that, and it is deliberately not taken here: this deployment runs the
> database queue, and the codebase already documents what happens when queued trading work
> has no worker — see `QUEUE_STRATEGY_EVALUATION`. Isolation was worth having on its own and
> costs nothing; parallelism needs a worker somebody is watching.

---

## The application watching itself

Everything above watches the *bot*. `ErrorReporter` watches this software, which until it
existed nothing did: a 500 on a customer settings page was invisible until they emailed, and
the only way to find one was reading `laravel.log` over SSH.

Every unhandled exception becomes a `critical` row on `/settings/activity` with source `app`, carrying the
exception class, the file and line, and the first frame inside `app/`. Laravel's own logging is
untouched - this adds an incident, it does not replace the stack trace in the file.

**It reports faults, not refusals.** A 404, a failed login, a rejected form, a throttled
client and any 4xx are the application working correctly. An error reporter that reports
everything buries the faults among them, which is how people learn to ignore one.

**Repetition is counted, not repeated.** A page throwing on every request would otherwise
write a row and send a message per request, taking the channel down alongside the page. The
first occurrence in each fifteen-minute window reports; the rest increment a counter that
travels with the next report as "N further occurrences since".

**Faults are deduplicated on class, file and line - never the message.** Messages carry ids,
symbols and balances, so signing on one would give the same broken line a new signature per
request and deduplicate nothing.

**The operator hears, the tenant does not.** An exception is a fault in this software; the
customer whose request hit it cannot act on a stack trace. The notification carries no owner,
which routes it to the platform's own address rather than a customer channel. The log row *is*
stamped with the tenant when one is current, so the fault is findable beside their activity.

Reporting never throws. An exception raised while reporting an exception is how a small fault
becomes an outage, and there is nowhere useful for it to go.

> This is the floor, not a replacement for a real reporter. Stack traces, aggregation,
> release tracking and search are genuine reasons to add one - but they need an account and
> a key, and this needed neither to stop faults being invisible.

---

## Not built

- **Any channel but Telegram.** Email and webhooks would be small additions; the notifier is one
  class.
- **Acknowledgement.** You cannot silence an incident from the dashboard; it clears when the
  condition clears.
- **Alerts on the dashboard.** They are recorded and visible on `/settings/activity`, but there is no banner.
  The Bot Status card covers the offline and blocked cases already.
- **Command failure alerting.** Repeated broker rejections are visible on `/settings/activity` and do not
  raise. Defining when that has "cleared" needs more thought than the other conditions did.
