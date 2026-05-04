#!/bin/bash
#
# Gold Digger - First Deployment Script
# Run this after server-setup.sh to do initial deployment
#
# Usage: ./scripts/first-deploy.sh <github-repo-url>
#

set -e

REPO_URL="${1:-}"
APP_DIR="/var/www/gold-digger"

if [ -z "$REPO_URL" ]; then
    echo "Usage: $0 <github-repo-url>"
    echo "Example: $0 git@github.com:yourusername/gold-digger.git"
    exit 1
fi

echo "================================================"
echo "  Gold Digger - First Deployment"
echo "================================================"

# Clone repository
echo "[1/9] Cloning repository..."
cd /var/www
rm -rf gold-digger
git clone $REPO_URL gold-digger
cd gold-digger

# Set ownership
echo "[2/9] Setting permissions..."
chown -R www-data:www-data $APP_DIR
chmod -R 755 $APP_DIR
chmod -R 775 $APP_DIR/storage $APP_DIR/bootstrap/cache

# Create .env file
echo "[3/9] Creating .env file..."
cp .env.example .env

# Load database credentials
if [ -f /root/.gold-digger-credentials ]; then
    source /root/.gold-digger-credentials
    sed -i "s/DB_DATABASE=.*/DB_DATABASE=$DB_DATABASE/" .env
    sed -i "s/DB_USERNAME=.*/DB_USERNAME=$DB_USERNAME/" .env
    sed -i "s/DB_PASSWORD=.*/DB_PASSWORD=$DB_PASSWORD/" .env
fi

# Update .env for production
sed -i "s/APP_ENV=.*/APP_ENV=production/" .env
sed -i "s/APP_DEBUG=.*/APP_DEBUG=false/" .env

echo ""
echo "IMPORTANT: Edit .env file now to set:"
echo "  - APP_URL (your domain)"
echo "  - Any API keys"
echo ""
read -p "Press Enter after editing .env to continue..."

# Install dependencies
echo "[4/9] Installing Composer dependencies..."
composer install --no-dev --optimize-autoloader --no-interaction

echo "[5/9] Installing NPM dependencies..."
npm ci

echo "[6/9] Building assets..."
npm run build

# Generate app key
echo "[7/9] Generating application key..."
php artisan key:generate --force

# Run migrations
echo "[8/9] Running migrations..."
php artisan migrate --force

# Create storage link and cache
echo "[9/9] Finalizing setup..."
php artisan storage:link
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Set final permissions
chown -R www-data:www-data $APP_DIR

echo ""
echo "================================================"
echo "  First deployment complete!"
echo "================================================"
echo ""
echo "Next steps:"
echo "  1. Set up SSL: sudo certbot --nginx -d yourdomain.com"
echo "  2. Create admin user: php artisan tinker"
echo "     > User::create(['name'=>'Admin','email'=>'you@email.com','password'=>bcrypt('password')])"
echo "  3. Set up GitHub Actions secrets (see DEPLOYMENT.md)"
echo ""
