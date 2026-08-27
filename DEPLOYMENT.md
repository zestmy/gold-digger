# Gold Digger - Deployment Guide

> **How deploys work now.** A push to `main` runs the test suite first, and the deploy job
> only starts if it passes - see `.github/workflows/deploy.yml`. Before migrations run, the
> deploy takes a compressed dump into `storage/backups` (`php artisan db:backup`, keeping the
> last 7). If any step fails, the workflow lifts maintenance mode rather than leaving the
> site down.
>
> **PHP version.** The scripts and examples below provision **8.2**. The deploy no longer
> hardcodes a version - it reloads whichever `php{major}.{minor}-fpm` the server's own PHP
> reports - but if you rebuild from `scripts/server-setup.sh`, check the nginx `fastcgi_pass`
> socket matches the PHP you actually installed.
>
> **After the deploy that adds the admin gate.** `/admin` is restricted to accounts with
> `is_admin`, and the migration grants it automatically only when the database holds exactly
> one user. Otherwise nobody has access until you run:
>
> ```bash
> php artisan user:admin you@example.com
> ```

## Prerequisites

- DigitalOcean account
- Domain name (optional but recommended)
- GitHub repository

## Quick Start

### 1. Create DigitalOcean Droplet

1. Go to DigitalOcean → Create → Droplets
2. Choose:
   - **Image**: Ubuntu 24.04 LTS
   - **Plan**: Basic, $12/mo (2GB RAM, 1 vCPU) minimum
   - **Region**: Closest to you
   - **Authentication**: SSH Key (recommended)
3. Create droplet and note the IP address

### 2. Initial Server Setup

SSH into your server:
```bash
ssh root@YOUR_SERVER_IP
```

Run the setup script:
```bash
curl -s https://raw.githubusercontent.com/YOUR_USER/gold-digger/main/scripts/server-setup.sh | bash -s -- yourdomain.com
```

Or manually:
```bash
wget https://raw.githubusercontent.com/YOUR_USER/gold-digger/main/scripts/server-setup.sh
chmod +x server-setup.sh
./server-setup.sh yourdomain.com
```

**Save the database credentials displayed at the end!**

### 3. First Deployment

```bash
cd /var/www
./gold-digger/scripts/first-deploy.sh git@github.com:YOUR_USER/gold-digger.git
```

### 4. Set Up SSL (HTTPS)

```bash
sudo certbot --nginx -d yourdomain.com -d www.yourdomain.com
```

### 5. Configure GitHub Actions

Add these secrets to your GitHub repository (Settings → Secrets → Actions):

| Secret | Value |
|--------|-------|
| `SERVER_HOST` | Your droplet IP or domain |
| `SERVER_USER` | `root` (or deploy user) |
| `SERVER_SSH_KEY` | Your private SSH key |

**To get your SSH key:**
```bash
# On your local machine
cat ~/.ssh/id_rsa
```

Or generate a deploy key:
```bash
ssh-keygen -t ed25519 -f ~/.ssh/gold-digger-deploy -C "deploy@gold-digger"
# Add public key to server's ~/.ssh/authorized_keys
# Add private key as SERVER_SSH_KEY secret
```

### 6. Push to Deploy

Now every push to `main` branch will auto-deploy:

```bash
git add .
git commit -m "My changes"
git push origin main
```

---

## Manual Deployment

SSH into server and run:
```bash
cd /var/www/gold-digger
./scripts/deploy.sh
```

---

## Server Management

### View Logs
```bash
# Application logs
tail -f /var/www/gold-digger/storage/logs/laravel.log

# Nginx logs
tail -f /var/log/nginx/error.log

# Queue worker logs
tail -f /var/www/gold-digger/storage/logs/worker.log
```

### Restart Services
```bash
# PHP-FPM
sudo systemctl restart php8.2-fpm

# Nginx
sudo systemctl restart nginx

# Queue workers
sudo supervisorctl restart gold-digger-worker:*
```

### Database Backup
```bash
# Create backup
mysqldump -u gold_digger -p gold_digger > backup_$(date +%Y%m%d).sql

# Restore backup
mysql -u gold_digger -p gold_digger < backup_20240101.sql
```

### Retention

Nothing in this application deleted anything until `data:prune` existed, and one table
dominates the consequences: measured on a working database, **`candles` was 91% of the
total** — and it is stored per broker account rather than shared, so it multiplies by
tenant. One symbol on M5 is roughly 75,000 bars a year at about 240 bytes each.

The command runs weekly from the scheduler. Run it by hand once before trusting that:

```bash
php artisan data:prune --dry
```

`--dry` reports every count and deletes nothing. Worth using first, because a prune that
turns out to have been wrong is not undone by editing the setting afterwards.

**Bars are counted, not dated.** A 90-day cutoff would leave 25,000 M5 bars and 1,500 H1
bars — the same policy barely touching one series and starving another. Retention is
therefore the newest N bars *per series*, where a series is one account's one symbol on one
timeframe. The default of 30,000 sits above `StrategyImprovement::DEFAULT_BARS` (20,000),
which is the deepest consumer; trading itself needs 300.

**What it will not touch, at any setting:** `trades`, `trade_partials`,
`trade_screenshots`, `daily_summaries` — the financial record — and `signals`,
`telegram_signals`, `chart_analyses` — the evidence for whether any of this works. That list
is not configurable, because a retention setting that could be turned up until it ate the
trade history is one somebody will eventually turn up.

Tunable in `config/trading.php` or by environment: `RETAIN_CANDLE_BARS`,
`RETAIN_BOT_LOG_DAYS`, `RETAIN_AI_USAGE_DAYS`, `RETAIN_RESOLVED_ALERT_DAYS`,
`RETAIN_ECONOMIC_EVENT_DAYS`. Set `RETAIN_CANDLE_BARS=0` to keep everything and accept
unbounded growth.

> InnoDB does not return freed pages to the filesystem, so `df` will not move after a
> prune — the table stays the same size on disk and reuses the space. That is normal, and
> the reason the command reports rows rather than megabytes saved.

### Clear Caches
```bash
cd /var/www/gold-digger
php artisan cache:clear
php artisan config:clear
php artisan route:clear
php artisan view:clear
```

---

## Environment Variables

Key `.env` settings for production:

```env
APP_NAME="FXSignalPro"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://fxsignal.pro

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=gold_digger
DB_USERNAME=gold_digger
DB_PASSWORD=your_password_here

# Optional: For Python bot integration
ELEV8_API_URL=
ELEV8_ACCOUNT_ID=
PYTHON_BOT_API_KEY=
```

---

## Telegram session worker

Adding a Telegram account is done in the browser, which means the platform signs tenants
in and holds their sessions. A separate process does that work — see
[`tools/telegram-worker/`](tools/telegram-worker/) for the systemd unit and the full
argument for why it is built this way.

Without it, tenants can add accounts but cannot sign them in: the page says so rather than
spinning on a request nothing will answer.

```env
# One application from https://my.telegram.org, shared by every tenant. Telegram
# rate-limits and bans per application, so a throttle here hits everyone at once.
TELEGRAM_APP_ID=
TELEGRAM_APP_HASH=

# Must match the worker's. It reaches every tenant's session, so it belongs with the
# database password, not on any dashboard page.
TELEGRAM_WORKER_TOKEN=

# false puts new accounts back on a collector the tenant runs themselves.
TELEGRAM_HOSTED_BY_DEFAULT=true
```

Two things worth knowing before turning this on:

- A stored session can read every chat on a tenant's account and post as them. It is
  encrypted with `APP_KEY`, so **losing or rotating `APP_KEY` makes every stored session
  unreadable** and every tenant has to sign in again.
- The worker endpoints under `/api/v1/telegram/worker/` hand out those sessions. They are
  guarded by the token above and should not be reachable from outside the network the
  worker runs on.

---

## Troubleshooting

### 500 Error
```bash
# Check Laravel logs
tail -50 /var/www/gold-digger/storage/logs/laravel.log

# Check permissions
sudo chown -R www-data:www-data /var/www/gold-digger
sudo chmod -R 755 /var/www/gold-digger
sudo chmod -R 775 /var/www/gold-digger/storage /var/www/gold-digger/bootstrap/cache
```

### Database Connection Error
```bash
# Test connection
mysql -u gold_digger -p -e "SELECT 1"

# Check credentials in .env
cat /var/www/gold-digger/.env | grep DB_
```

### Assets Not Loading
```bash
cd /var/www/gold-digger
npm run build
php artisan storage:link
```

### Queue Not Processing
```bash
sudo supervisorctl status
sudo supervisorctl restart gold-digger-worker:*
```

---

## Security Recommendations

1. **Firewall**: Enable UFW
   ```bash
   ufw allow 22
   ufw allow 80
   ufw allow 443
   ufw enable
   ```

2. **Fail2ban**: Protect against brute force
   ```bash
   apt install fail2ban
   systemctl enable fail2ban
   ```

3. **Regular Updates**
   ```bash
   apt update && apt upgrade -y
   ```

4. **Backup Strategy**: Set up automated backups via DigitalOcean or cron

---

## Cost Estimate

| Service | Monthly Cost |
|---------|-------------|
| DigitalOcean Droplet (2GB) | $12 |
| Domain (optional) | ~$1 |
| Backups (optional) | $2.40 |
| **Total** | **~$15/mo** |
