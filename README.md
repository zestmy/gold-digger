# Gold Digger

Automated XAUUSD (gold) scalping bot: a Laravel dashboard that decides, an MQL5 Expert
Advisor that executes, and a Telegram signal copier alongside.

## Overview

- **Laravel dashboard** — monitoring, configuration, analytics, and the strategy layer
  itself. It also serves the bot API the executor talks to. [`ARCHITECTURE.md`](ARCHITECTURE.md)
  is the one-page map of how the pieces fit.
- **MQL5 Expert Advisor** in [`mql5/`](mql5/) — the executor. It runs inside the MT5
  terminal, polls this dashboard for commands and reports fills back. Setup is in
  [`docs/MT5_EA_BRIDGE.md`](docs/MT5_EA_BRIDGE.md).
- **MySQL** — trades, signals, logs, the command queue and the job queue.
- **Python tooling** in [`bot/`](bot/) — a preflight diagnostic and a reference executor.
  It does not trade; see [`bot/README.md`](bot/README.md).

Entries are decided here, not in the terminal: the EA pushes closed bars, the dashboard
computes the indicators and queues the order. See
[`docs/SIGNAL_GENERATION.md`](docs/SIGNAL_GENERATION.md), and
[`docs/TRADE_MANAGEMENT.md`](docs/TRADE_MANAGEMENT.md) for the take-profit ladder, the
reversal and time exits, and the break-even stop. Positions the dashboard did not open
are picked up by [`docs/RECONCILIATION.md`](docs/RECONCILIATION.md).

> **Looking for something to trade?** [`docs/MARKET_SCAN.md`](docs/MARKET_SCAN.md) — `/signals/scan`
> ranks every instrument there are bars for on measured evidence, then asks one question of a
> model: of this shortlist, which. The ranking is arithmetic and works with no API key.

> **Changing a strategy setting?** [`docs/BACKTESTING.md`](docs/BACKTESTING.md) — `php artisan
> backtest` replays it over the stored bars using the same evaluator that trades, so a change can
> be measured instead of argued about.

> **Is the signal actually any good?** [`docs/SIGNAL_OUTCOMES.md`](docs/SIGNAL_OUTCOMES.md) —
> every signal, traded or not, is scored against the bars that followed it, so the win rate
> has a sample size and the confidence score has something to be measured against.

> **Wondering why it stood aside?** [`docs/NEWS_FILTER.md`](docs/NEWS_FILTER.md) — the bot
> refuses entries around high-impact releases, and holds them entirely when the calendar is
> stale rather than trading through one unseen.

> **Wondering what the AI is allowed to do?** [`docs/AI_INTEGRATION.md`](docs/AI_INTEGRATION.md)
> — nine call sites behind one key, bounded by a fund cap and a daily request allowance,
> and a single rule: the model never produces a number that becomes a price.

> **Where do the bars come from?** [`docs/MARKET_DATA.md`](docs/MARKET_DATA.md) - deep
> history for a replay is fetched on demand and never stored, because one consumer wanted
> 20,000 bars where the next deepest wanted 300. What decides a price still reads the
> terminal's own series, and there is no setting that changes that.

> **Building a client against this?** [`docs/ANALYSIS_API.md`](docs/ANALYSIS_API.md) - six
> endpoints on the token the EA already uses, split so that reading structure costs
> nothing and only asking a model does.

> **More than one person using this?** [`docs/TENANCY.md`](docs/TENANCY.md) — isolation is
> a property of the model now rather than 93 remembered `where` clauses, because the one
> time it was forgotten every tenant could read, and delete, every other tenant's logs.

> **Running it unattended?** [`docs/MONITORING.md`](docs/MONITORING.md) covers the health checks
> and Telegram alerting. A dashboard only helps somebody who is looking at it.

> **Never run the Expert Advisor before?** [`docs/COMMISSIONING.md`](docs/COMMISSIONING.md) is the
> sequence for getting it from never-compiled to a verified round trip on a demo account. It has
> never been through a compiler, and that is the gating step for everything else.

> **Picking this work back up?** Start at [`docs/HANDOFF.md`](docs/HANDOFF.md) — what is built,
> what is deliberately not, what has never been verified, and the next actions in order.

> **Orders being rejected?** [`docs/MT5_EXECUTION.md`](docs/MT5_EXECUTION.md) ranks the causes of
> MT5 order rejections with a full retcode reference. `bot/mt5_preflight.py` tells you which one
> applies to your account.

## Prerequisites

- **PHP 8.2+** with extensions: mbstring, xml, curl, mysql, intl
- **Composer** 2.x
- **Node.js** 20+ and npm
- **MySQL 8.0+**
- **Laravel Herd** (recommended for Windows) or equivalent local development environment

## Setup Instructions

### 1. Clone and Install Dependencies

```bash
cd C:\WebDev\gold-digger

# Install PHP dependencies
composer install

# Install Node dependencies
npm install
```

### 2. Environment Configuration

```bash
# Copy environment file (if .env doesn't exist)
cp .env.example .env

# Generate application key (if not already set)
php artisan key:generate
```

The default `.env` is configured for Laravel Herd with MySQL:
- Database: `gold_digger`
- Username: `root`
- Password: (empty)

Set `APP_URL` to the address the dashboard is actually reached on (`http://gold-digger.test`
locally, `https://your-host` in production). It is not cosmetic: `/terminal/download` builds
the EA archive per request with `APP_URL` written into its `ApiBaseUrl` default, and that is
the URL the terminal has to whitelist. An EA downloaded while `APP_URL` still says
`http://localhost` will point at the wrong place. It is also sent to OpenRouter as the
attribution referer.

### 3. Create Database

Using MySQL CLI or phpMyAdmin:
```sql
CREATE DATABASE gold_digger CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

### 4. Run Migrations

```bash
php artisan migrate
```

### 5. Create Storage Link

```bash
php artisan storage:link
```

### 6. Start Development Servers

**Terminal 1 - Vite (frontend assets):**
```bash
npm run dev
```

**Terminal 2 - Laravel (or use Herd):**
```bash
php artisan serve
```

Or access via Laravel Herd URL: `http://gold-digger.test`

The scheduler and the queue are separate processes. Nothing in `routes/console.php` runs
without `php artisan schedule:work` (or cron calling `schedule:run` every minute), and
`php artisan queue:work` is only needed once `QUEUE_STRATEGY_EVALUATION=true` or the
strategy improver is used. Locally, the dashboard and the EA work without either.

### 7. Create the First Account

Public registration is off by default (`REGISTRATION_ENABLED=false`), because an open
sign-up form on a box holding broker credentials invites accounts nobody asked for. Create
the first account from the console instead:

```bash
php artisan user:create you@example.com --admin
```

`--admin` sets `users.is_admin`, which is what gates `/admin`. It can be granted or revoked
later with `php artisan user:admin you@example.com`.

The alternative is `REGISTRATION_ENABLED=true` in `.env`, which defines `/register` and makes
the landing page show its sign-up buttons. That is the setting for running this as something
people join.

Either way, creating a user also creates:
- Default bot settings, with the bot switched **off**
- A default "Fira-Style Gold Trend Scalp" strategy (H1 trend, M5 entries)

## Routes

| Route | Description |
|-------|-------------|
| `/` | Landing page |
| `/login` | Sign in |
| `/register` | Sign up — only defined when `REGISTRATION_ENABLED=true` |
| `/dashboard` | **Home** — today's signals, open positions, the 30-day curve, terminal status and the auto-trade controls |
| `/signals` | **Signals** — the AI signals with the entry card; `/signals/copied` is the Telegram copier pipeline; `/signals/scan` ranks every instrument on measured evidence |
| `/providers` | **Providers** — which Telegram channels are followed and what each has been worth; `/providers/accounts` manages the accounts that read them |
| `/trades` | **Trades** — open positions; `/trades/history` closed trades; `/trades/performance` the analytics computed from `trades` |
| `/auto-trade` | **Auto-Trade** — the four things that must be true before a signal becomes a position; `/auto-trade/terminal` issues the EA token (`/auto-trade/terminal/download` ships the EA configured for this dashboard); `/auto-trade/accounts` MT5 accounts; `/auto-trade/risk` risk, sessions, filters and the AI fund |
| `/settings` | **Settings** — profile, password, two-factor, sessions, Telegram alerts; `/settings/activity` is the log written by the EA, the monitor and the copier |
| `/strategies` | Operator only: strategy parameters; `/strategies/improve` is the AI proposer with walk-forward. Both 403 for a subscriber |
| `/admin` | Filament support console, for `users.is_admin` only |

The older addresses (`/setup`, `/terminal`, `/broker-accounts`, `/trades/live`, `/analytics`,
`/analysis`, `/signals/copier`, `/signals/channels`, `/signals/accounts`, `/logs`,
`/profile`) redirect to where the page went.

## Bot API

The Expert Advisor talks to these endpoints, authenticated with a bearer token from
`bot_tokens`. Issue one with:

```bash
php artisan bot:token you@example.com --name="Windows VPS" --account=1
```

| Method | Endpoint | Purpose |
|--------|----------|---------|
| `GET` | `/api/v1/bot/commands` | Claim queued commands |
| `POST` | `/api/v1/bot/commands/{id}/result` | Report the broker's answer |
| `POST` | `/api/v1/bot/fills` | Record opens and closes |
| `POST` | `/api/v1/bot/heartbeat` | Liveness, account snapshot, symbol spec + kill-switch state |
| `POST` | `/api/v1/bot/logs` | Write to `bot_logs` |
| `POST` | `/api/v1/bot/candles` | Push closed bars; a new bar triggers signal generation |
| `POST` | `/api/v1/bot/positions` | Snapshot of open positions, so `trades` can be corrected |

Protocol details: [`docs/MT5_EA_BRIDGE.md`](docs/MT5_EA_BRIDGE.md).

The same token also authenticates `/api/v1/analysis/*` ([`docs/ANALYSIS_API.md`](docs/ANALYSIS_API.md))
and `/api/v1/telegram/*` for a self-hosted collector ([`tools/telegram-collector/`](tools/telegram-collector/)).
The hosted session worker under `/api/v1/telegram/worker/*` uses `TELEGRAM_WORKER_TOKEN`
instead — an infrastructure credential, not an issued one ([`tools/telegram-worker/`](tools/telegram-worker/)).

## Admin Panel

The Filament panel at `/admin` is a support console: it reads across every tenant, which is
why it is limited to accounts with `users.is_admin`. It has no login page of its own — sign
in at `/login` (with two-factor, if enrolled) and then open `/admin`. Resources for trades,
trade partials, signals, strategies, broker accounts, bot settings and bot logs, each with
edit and bulk delete. Any write to another user's row is recorded in `admin_actions` — see
[`docs/TENANCY.md`](docs/TENANCY.md).

## Common Issues (Windows + Laravel Herd)

### MySQL Connection Refused

**Symptom:** `SQLSTATE[HY000] [2002] Connection refused`

**Fix:**
1. Ensure MySQL is running in Herd
2. Check MySQL port (default: 3306)
3. Verify credentials in `.env`

### Storage Link Permission Errors

**Symptom:** `php artisan storage:link` fails, or `/storage/...` returns 404

**Fix:**
```bash
# Remove existing link if broken
rm public/storage

# Recreate with admin privileges
php artisan storage:link
```

### Vite Dev Server Port Conflicts

**Symptom:** `EADDRINUSE: address already in use`

**Fix:**
1. Find process using port 5173:
   ```bash
   netstat -ano | findstr :5173
   ```
2. Kill the process or change Vite port in `vite.config.js`

### PHP Version Mismatch

**Symptom:** Composer errors about PHP version

**Fix:**
1. Check PHP version: `php -v`
2. Ensure using PHP 8.2+
3. With Herd, use the PHP selector in the system tray

### intl Extension Missing

**Symptom:** Filament installation fails with `ext-intl` error

**Fix:**
1. In `php.ini`, enable: `extension=intl`
2. Restart PHP/Herd
3. Verify: `php -m | findstr intl`

## Tech Stack

- **Backend**: Laravel 12, PHP 8.2
- **Frontend**: Livewire 3 (with Volt for the auth pages), Tailwind CSS 3, Alpine.js, Lightweight Charts
- **Admin**: Filament 3
- **Database**: MySQL 8 — also the session store, cache and queue driver
- **Executor**: MQL5, in the MetaTrader 5 terminal
- **AI**: OpenRouter, one key in front of every model
- **Outside processes**: Python for the Telegram collector and worker (`tools/`)

## Project Structure

```
gold-digger/
├── app/
│   ├── Console/Commands/      # bot:token, bot:monitor, backtest, telegram:*, data:prune ...
│   ├── Filament/Resources/    # Admin panel resources
│   ├── Http/
│   │   ├── Controllers/Api/   # Bot, Analysis and Telegram endpoints
│   │   └── Middleware/        # AuthenticateBot, AuthenticateWorker, BindWorkerAccount
│   ├── Jobs/                  # EvaluateNewBars, RunStrategyImprovement
│   ├── Livewire/
│   │   ├── Dashboard/         # Dashboard card components
│   │   └── Pages/             # Full-page Livewire components
│   ├── Models/                # Eloquent models; Concerns/BelongsToTenant
│   ├── Observers/             # UserObserver, AdminActionObserver
│   ├── Services/              # Strategy, Telegram, Ai, Monitoring, MarketData, Backtest ...
│   └── Support/Tenancy/       # Tenant, TenantSweep
├── bot/                       # Python MT5 diagnostics + reference executor
├── database/migrations/       # Database schema
├── docs/                      # Per-topic design notes
├── mql5/                      # MetaTrader 5 Expert Advisor (the executor)
├── tools/                     # Telegram collector and hosted session worker (Python)
├── routes/
│   ├── api.php                # /api/v1/bot, /api/v1/analysis, /api/v1/telegram
│   ├── console.php            # The schedule
│   └── web.php                # Dashboard routes
├── resources/views/
│   ├── layouts/               # App layout with sidebar
│   └── livewire/              # Livewire component views
└── tests/Feature/             # Including the EA wire-protocol contract test
```

## License

Private project - All rights reserved.
