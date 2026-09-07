#!/bin/bash
#
# Gold Digger - Manual Deployment Script
# Run this on the server to deploy latest changes
#
# Usage: ./scripts/deploy.sh [branch]
#
# Mirrors the deploy job in .github/workflows/deploy.yml. When one changes, change the other.
#

set -e

APP_DIR="/var/www/gold-digger"
BRANCH="${1:-main}"

echo "================================================"
echo "  Deploying Gold Digger"
echo "  Branch: $BRANCH"
echo "================================================"

cd $APP_DIR

# Preflight, before anything is touched. Strategy evaluation goes onto the `strategy`
# queue, so a box whose worker is missing stores every bar and never evaluates one, and
# the only thing that says so is the queue_stalled alert - a quarter of an hour later.
# ensure-worker.sh installs supervisor and the program when they are missing and fails
# only if the worker still will not run - with the site still up either way. Read from
# the branch being deployed, since the working tree is still the previous release.
git fetch origin
git show "origin/$BRANCH:scripts/ensure-worker.sh" | APP_DIR="$APP_DIR" bash

# `set -e` plus `php artisan down` means any failure below would otherwise leave the site
# in maintenance mode with nobody told. The trap lifts it on every exit, success or not:
# the code is whatever the failing step left behind, but the previous release is still
# serving in every case except a failure between the git reset and the cache rebuild -
# and a site that is up and slightly wrong is easier to notice than one that is down and
# says "be right back".
lift_maintenance() {
    php artisan up || true
}
trap lift_maintenance EXIT

# Put app in maintenance mode
echo "[1/8] Enabling maintenance mode..."
php artisan down --retry=60 || true

# Pull latest code
echo "[2/8] Pulling latest code..."
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

# Derived rather than hardcoded, as deploy.yml does. A hardcoded `php8.2-fpm` reloads a
# unit that may not exist on a box provisioned with another version, and under `set -e`
# that abort used to be the one that stranded the site in maintenance mode.
PHP_FPM="php$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')-fpm"
sudo systemctl reload "$PHP_FPM" || sudo systemctl reload php-fpm

# Workers hold the old code in memory until they recycle. queue:restart asks them to
# finish the current job and exit; supervisor starts them again on the new code.
php artisan queue:restart || true
sudo supervisorctl restart gold-digger-worker:*

# Bring app back online. The trap would do this anyway; doing it here makes the success
# path explicit and keeps the completion banner after it.
php artisan up
trap - EXIT

echo ""
echo "================================================"
echo "  Deployment complete!"
echo "  $(date)"
echo "================================================"
