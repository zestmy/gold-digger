#!/bin/bash
#
# Gold Digger - Manual Deployment Script
# Run this on the server to deploy latest changes
#
# Usage: ./scripts/deploy.sh
#

set -e

APP_DIR="/var/www/gold-digger"
BRANCH="${1:-main}"

echo "================================================"
echo "  Deploying Gold Digger"
echo "  Branch: $BRANCH"
echo "================================================"

cd $APP_DIR

# Put app in maintenance mode
echo "[1/8] Enabling maintenance mode..."
php artisan down --retry=60 || true

# Pull latest code
echo "[2/8] Pulling latest code..."
git fetch origin
git reset --hard origin/$BRANCH

# Install PHP dependencies
echo "[3/8] Installing Composer dependencies..."
composer install --no-dev --optimize-autoloader --no-interaction

# Install Node dependencies and build
echo "[4/8] Building frontend assets..."
npm ci
npm run build

# Run migrations
echo "[5/8] Running migrations..."
php artisan migrate --force

# Clear and rebuild caches
echo "[6/8] Rebuilding caches..."
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

# Ensure storage link exists
echo "[7/8] Ensuring storage link..."
php artisan storage:link --force 2>/dev/null || true

# Restart services
echo "[8/8] Restarting services..."
sudo systemctl reload php8.2-fpm
sudo supervisorctl restart gold-digger-worker:*

# Bring app back online
php artisan up

echo ""
echo "================================================"
echo "  Deployment complete!"
echo "  $(date)"
echo "================================================"
