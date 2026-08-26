# Gold Digger

Personal automated gold scalping trading bot with Laravel web dashboard.

## Overview

Gold Digger is a multi-component trading system designed for XAUUSD (gold) scalping:
- **Laravel Dashboard**: Web interface for monitoring, configuration, and analytics
- **Python Bot** (Phase 2+): Trading engine connecting to MT5 broker — see [`bot/`](bot/)
- **MySQL Database**: Shared data store for trades, signals, and logs

Trade execution runs through an **MQL5 Expert Advisor** in [`mql5/`](mql5/) that polls this
dashboard and reports fills back — see [`docs/MT5_EA_BRIDGE.md`](docs/MT5_EA_BRIDGE.md) for setup.

Entries are decided here, not in the terminal: the EA pushes closed bars, the dashboard
computes the indicators and queues the order. See
[`docs/SIGNAL_GENERATION.md`](docs/SIGNAL_GENERATION.md), and
[`docs/TRADE_MANAGEMENT.md`](docs/TRADE_MANAGEMENT.md) for the take-profit ladder, the
reversal and time exits, and the break-even stop. Positions the dashboard did not open
are picked up by [`docs/RECONCILIATION.md`](docs/RECONCILIATION.md).

> **Looking for something to trade?** [`docs/MARKET_SCAN.md`](docs/MARKET_SCAN.md) — `/analysis`
> ranks every instrument there are bars for on measured evidence, then asks one question of a
> model: of this shortlist, which. The ranking is arithmetic and works with no API key.

> **Changing a strategy setting?** [`docs/BACKTESTING.md`](docs/BACKTESTING.md) — `php artisan
> backtest` replays it over the stored bars using the same evaluator that trades, so a change can
> be measured instead of argued about.

> **Wondering why it stood aside?** [`docs/NEWS_FILTER.md`](docs/NEWS_FILTER.md) — the bot
> refuses entries around high-impact releases, and holds them entirely when the calendar is
> stale rather than trading through one unseen.

> **Wondering what the AI is allowed to do?** [`docs/AI_INTEGRATION.md`](docs/AI_INTEGRATION.md)
> — nine call sites behind one key, bounded by a fund cap, and a single rule: the model
> never produces a number that becomes a price.

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

### 7. Register First User

1. Visit `/register`
2. Create your account
3. You'll be redirected to the dashboard

The system automatically creates:
- Default bot settings (conservative risk management)
- Default "Fira-Style Gold Trend Scalp" strategy

## Routes

| Route | Description |
|-------|-------------|
| `/` | Landing page |
| `/register` | User registration |
| `/login` | User login |
| `/dashboard` | Main dashboard with stats and controls |
| `/trades/live` | Live trades (Phase 1B) |
| `/trades/history` | Trade history (Phase 1B) |
| `/strategies` | Strategy configuration (Phase 1B) |
| `/broker-accounts` | MT5 account management |
| `/analytics` | Performance analytics (Phase 1C) |
| `/settings` | Bot settings (Phase 1B) |
| `/logs` | Bot logs (written by the EA) |
| `/admin` | Filament admin panel |

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

## Admin Panel

Access the Filament admin panel at `/admin` for direct CRUD operations on all models:
- Trades (with partials and screenshots)
- Strategies
- Broker Accounts
- Bot Settings
- Signals
- Bot Logs
- Daily Summaries

## Common Issues (Windows + Laravel Herd)

### MySQL Connection Refused

**Symptom:** `SQLSTATE[HY000] [2002] Connection refused`

**Fix:**
1. Ensure MySQL is running in Herd
2. Check MySQL port (default: 3306)
3. Verify credentials in `.env`

### Storage Link Permission Errors

**Symptom:** Cannot access uploaded files or screenshots

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
- **Frontend**: Livewire 3, Tailwind CSS, Alpine.js
- **Admin**: Filament v4
- **Database**: MySQL 8
- **Image Processing**: Intervention Image

## Project Structure

```
gold-digger/
├── app/
│   ├── Filament/Resources/    # Admin panel resources
│   ├── Livewire/
│   │   ├── Dashboard/         # Dashboard card components
│   │   └── Pages/             # Full-page Livewire components
│   ├── Models/                # Eloquent models
│   └── Observers/             # Model observers
├── bot/                       # Python MT5 diagnostics + reference executor
├── database/migrations/       # Database schema
├── docs/                      # Design notes and analysis
├── mql5/                      # MetaTrader 5 Expert Advisor (the executor)
├── routes/api.php             # Bot API: /api/v1/bot/*
├── resources/views/
│   ├── layouts/               # App layout with sidebar
│   └── livewire/              # Livewire component views
└── routes/web.php             # Web routes
```

## License

Private project - All rights reserved.
