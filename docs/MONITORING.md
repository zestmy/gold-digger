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
| `feed_stalled:{timeframe}` | warning | A bar arrives within three bar-lengths |
| `daily_loss_limit` | critical | Realised losses fall back inside the limit — in practice, tomorrow |
| `queue_stalled` | critical | A worker starts draining the queue *(only when queued evaluation is on)* |
| `news_calendar_stale` | warning | The calendar reaches far enough ahead again *(only when the news filter is on)* |

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
visible on `/logs`, they simply reach nobody.

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

---

## Not built

- **Any channel but Telegram.** Email and webhooks would be small additions; the notifier is one
  class.
- **Acknowledgement.** You cannot silence an incident from the dashboard; it clears when the
  condition clears.
- **Alerts on the dashboard.** They are recorded and visible on `/logs`, but there is no banner.
  The Bot Status card covers the offline and blocked cases already.
- **Command failure alerting.** Repeated broker rejections are visible on `/logs` and do not
  raise. Defining when that has "cleared" needs more thought than the other conditions did.
