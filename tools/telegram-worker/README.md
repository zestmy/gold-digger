# Hosted Telegram session worker

Signs tenants into Telegram and reads their channels, so adding an account is something a
person does in a browser rather than by installing Python on a machine of their own.

## What this costs

The [self-hosted collector](../telegram-collector/) keeps the session where the reading
happens. That is the safer arrangement, and it is why it was built that way: if the
dashboard never holds a session, a dashboard compromise cannot become a Telegram account
takeover.

This program trades that property for an onboarding flow a customer will finish. The
sessions it holds can read every chat on a tenant's account and post as them. They are
encrypted at rest with `APP_KEY`, hidden from every response a browser can reach, and
served only to an infrastructure credential. That is mitigation, not equivalence.

Both modes still exist. `TELEGRAM_HOSTED_BY_DEFAULT=false` puts new accounts back on the
collector, and accounts created before hosting existed stay self-hosted.

## Configuration

Set by whoever deploys the platform. **A tenant configures nothing** — that is the point.

| Variable | |
|---|---|
| `GD_BASE_URL` | The dashboard, e.g. `https://fxsignal.pro` |
| `TELEGRAM_WORKER_TOKEN` | Must equal the dashboard's. Reaches every tenant's session — treat it like the database password |
| `TELEGRAM_APP_ID` | One application from https://my.telegram.org, shared by all tenants |
| `TELEGRAM_APP_HASH` | |
| `TELEGRAM_WORKER_POLL` | Seconds between fleet reconciles. Default 10 |
| `GD_REFRESH_SECONDS` | Seconds between watch-list re-reads. Default 60 |

Missing any of the first four and it exits naming them, rather than starting and failing
per-account later.

```bash
pip install -r requirements.txt
python worker.py
```

## Running it

```ini
# /etc/systemd/system/fxsignalpro-telegram-worker.service
[Unit]
Description=FXSignalPro hosted Telegram session worker
After=network-online.target

[Service]
Type=simple
User=www-data
WorkingDirectory=/var/www/gold-digger/tools/telegram-worker
EnvironmentFile=/etc/fxsignalpro/telegram-worker.env
ExecStart=/usr/bin/python3 worker.py
Restart=always
RestartSec=10

# The env file holds a credential that reaches every session.
UMask=0077

[Install]
WantedBy=multi-user.target
```

`chmod 600` the environment file and keep it out of the repository.

Restarting is safe: checkpoints live on the dashboard rather than on disk, so a redeploy
does not re-send the tail of every watched channel. That matters more than it sounds — a
re-sent signal is a signal that can be acted on twice.

## Design

One process, one `asyncio` loop, one Telethon client per hosted account. `supervise()`
reconciles against `GET /api/v1/telegram/worker/accounts` rather than reacting to events,
so an account added a moment ago and one signed in for a month need no different handling
and a missed event cannot leave the fleet drifting.

The sign-in conversation, message shaping and catch-up are **imported from the collector**,
not reimplemented. Two implementations of one state machine is how you get a sign-in that
works self-hosted and hangs hosted. The only thing overridden is a `call` seam that
rewrites the collector's endpoint paths (`messages`, `channels`, `login`) onto this
worker's account-scoped ones.

Message ingest posts to the same `CollectorController` the collector uses. Idempotency on
chat plus message id, the channel enable switch and the parser are things there must only
ever be one of.
