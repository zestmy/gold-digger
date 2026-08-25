# Telegram account collector

Reads signal channels with your own Telegram account and posts what it sees to the Gold
Digger dashboard.

## Why this exists

A Telegram **bot** can only see chats it has been added to. Signal providers do not add
your bot to their channel, so the Bot API cannot read them — that is not a setting, it is
what the Bot API is. Reading a provider's channel means MTProto, signed in as a real user
account.

## Why it is not part of the dashboard

The sign-in produces a `.session` file which is a **full account credential**: it can read
every chat your account has and post as you. Keeping that on the web server would turn a
website compromise into a Telegram account takeover — a much worse outcome than this
feature is worth.

So the collector runs wherever you choose. It holds the session there, and the dashboard
only ever receives message text over a bearer token you can revoke. Same shape as the
MetaTrader Expert Advisor: an outside process observing something the dashboard cannot
reach.

## Setup

**1. Get API credentials.** Go to <https://my.telegram.org> → *API development tools* and
create an application. You get an `api_id` and an `api_hash`. These identify the *client*,
not your account.

**2. Get a dashboard token.** Dashboard → **Terminal Setup** → issue a token. Copy it; it
is shown once.

**3. Install.**

```bash
cd tools/telegram-collector
python -m venv .venv && . .venv/bin/activate
pip install -r requirements.txt
cp .env.example .env    # then fill it in
set -a; . ./.env; set +a
```

**4. Sign in.** Once, interactively — phone number, the code Telegram sends you, and your
two-factor password if you have one.

```bash
python collector.py login
```

**5. Tell the dashboard what you can see.**

```bash
python collector.py announce
```

Every channel and group your account is in is now listed under **Signals → Channels**, all
of them disabled. Registering grants nothing: joining a channel to read it must not be the
same gesture as trading it.

**6. Enable the ones you want** in the dashboard, then run it:

```bash
python collector.py run
```

It forwards only enabled channels. Chats you have not enabled are filtered here and never
reach the web server at all — which matters, because your account is in conversations that
have nothing to do with trading.

Enabling or disabling a channel takes effect within a minute; no restart needed.

## Running it as a service

```ini
# /etc/systemd/system/gold-digger-collector.service
[Unit]
Description=Gold Digger Telegram collector
After=network-online.target

[Service]
Type=simple
User=collector
WorkingDirectory=/opt/gold-digger-collector
EnvironmentFile=/opt/gold-digger-collector/.env
# -u, because Python block-buffers stdout when it is not a terminal - without it the
# service log stays empty for hours and a working collector looks like a hung one.
Environment=PYTHONUNBUFFERED=1
ExecStart=/opt/gold-digger-collector/.venv/bin/python -u collector.py run
Restart=always
RestartSec=10

# The session file is an account credential. Nothing here needs the rest of the machine.
NoNewPrivileges=true
PrivateTmp=true
ProtectSystem=strict
ProtectHome=true
ReadWritePaths=/opt/gold-digger-collector

[Install]
WantedBy=multi-user.target
```

```bash
sudo systemctl enable --now gold-digger-collector
journalctl -u gold-digger-collector -f
```

## Keeping the session safe

- `chmod 600 gold-digger.session` and `chmod 700` its directory. Telethon creates the
  session world-readable, so this is a step, not a reassurance.
- Anyone with that file can read your Telegram and post as you. It is a password.
- Run the collector as its own unprivileged user, not as the web application's. The two
  have no reason to be able to read each other's secrets.
- Revoke it from Telegram itself: **Settings → Devices**, end the session named after this
  client.
- Revoking the *dashboard* token stops the collector without touching your Telegram
  account. Revoking the *Telegram* session does the opposite. They are separate on purpose.

## What it does not do

It does not join channels, send messages, react, or read anything outside the watch list.
It only reads and forwards.

## If nothing arrives

- `python collector.py announce` — if the channel is not listed, your account is not in it.
- Check it is enabled under **Signals → Channels**.
- The dashboard records every message it receives, including ones it cannot parse. If
  **Signals → Channels** shows messages arriving but a low parse rate, the provider's
  format is one the parser does not recognise yet — the raw text is on the Copier page.
