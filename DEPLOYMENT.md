# Gold Digger - Deployment Guide

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
APP_NAME="Gold Digger"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://yourdomain.com

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=gold_digger
DB_USERNAME=gold_digger
DB_PASSWORD=your_password_here

# Optional: For Python bot integration
OCTA_API_URL=
OCTA_ACCOUNT_ID=
PYTHON_BOT_API_KEY=
```

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
