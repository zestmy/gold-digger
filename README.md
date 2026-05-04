# Gold Digger

Personal automated gold scalping trading bot with Laravel web dashboard.

## Overview

Gold Digger is a multi-component trading system designed for XAUUSD (gold) scalping:
- **Laravel Dashboard**: Web interface for monitoring, configuration, and analytics
- **Python Bot** (Phase 2+): Trading engine connecting to MT5 broker
- **MySQL Database**: Shared data store for trades, signals, and logs

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
| `/broker-accounts` | MT5 account management (Phase 2) |
| `/analytics` | Performance analytics (Phase 1C) |
| `/settings` | Bot settings (Phase 1B) |
| `/logs` | Bot logs (Phase 3) |
| `/admin` | Filament admin panel |

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
├── database/migrations/       # Database schema
├── resources/views/
│   ├── layouts/               # App layout with sidebar
│   └── livewire/              # Livewire component views
└── routes/web.php             # Web routes
```

## License

Private project - All rights reserved.
