#!/bin/bash
#
# Gold Digger - DigitalOcean Server Setup Script
# Run this on a fresh Ubuntu 22.04/24.04 droplet
#
# Usage: curl -s https://raw.githubusercontent.com/YOUR_USER/gold-digger/main/scripts/server-setup.sh | sudo bash
#

set -e

# Configuration
APP_NAME="gold-digger"
APP_DIR="/var/www/$APP_NAME"
APP_USER="www-data"
DOMAIN="${1:-your-domain.com}"  # Pass domain as first argument
DB_NAME="gold_digger"
DB_USER="gold_digger"
DB_PASS=$(openssl rand -base64 32)
PHP_VERSION="8.2"

echo "================================================"
echo "  Gold Digger Server Setup"
echo "  Domain: $DOMAIN"
echo "================================================"

# Update system
echo "[1/10] Updating system packages..."
apt-get update && apt-get upgrade -y

# Install required packages
echo "[2/10] Installing required packages..."
apt-get install -y \
    nginx \
    mysql-server \
    php${PHP_VERSION}-fpm \
    php${PHP_VERSION}-cli \
    php${PHP_VERSION}-mysql \
    php${PHP_VERSION}-mbstring \
    php${PHP_VERSION}-xml \
    php${PHP_VERSION}-curl \
    php${PHP_VERSION}-zip \
    php${PHP_VERSION}-gd \
    php${PHP_VERSION}-intl \
    php${PHP_VERSION}-bcmath \
    php${PHP_VERSION}-redis \
    git \
    unzip \
    curl \
    certbot \
    python3-certbot-nginx \
    supervisor

# Install Node.js 22
echo "[3/10] Installing Node.js 22..."
curl -fsSL https://deb.nodesource.com/setup_22.x | bash -
apt-get install -y nodejs

# Install Composer
echo "[4/10] Installing Composer..."
curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# Configure MySQL
echo "[5/10] Configuring MySQL..."
mysql -e "CREATE DATABASE IF NOT EXISTS $DB_NAME CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -e "CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';"
mysql -e "GRANT ALL PRIVILEGES ON $DB_NAME.* TO '$DB_USER'@'localhost';"
mysql -e "FLUSH PRIVILEGES;"

# Create application directory
echo "[6/10] Setting up application directory..."
mkdir -p $APP_DIR
chown -R $APP_USER:$APP_USER $APP_DIR

# Configure Nginx
echo "[7/10] Configuring Nginx..."
cat > /etc/nginx/sites-available/$APP_NAME << 'NGINX'
server {
    listen 80;
    listen [::]:80;
    server_name DOMAIN_PLACEHOLDER;
    root /var/www/gold-digger/public;

    add_header X-Frame-Options "SAMEORIGIN";
    add_header X-Content-Type-Options "nosniff";

    index index.php;

    charset utf-8;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }

    error_page 404 /index.php;

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_hide_header X-Powered-By;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }

    # Increase upload size for screenshots
    client_max_body_size 20M;
}
NGINX

sed -i "s/DOMAIN_PLACEHOLDER/$DOMAIN/g" /etc/nginx/sites-available/$APP_NAME
ln -sf /etc/nginx/sites-available/$APP_NAME /etc/nginx/sites-enabled/
rm -f /etc/nginx/sites-enabled/default
nginx -t && systemctl reload nginx

# Configure PHP-FPM
echo "[8/10] Optimizing PHP-FPM..."
cat > /etc/php/${PHP_VERSION}/fpm/pool.d/www.conf << 'PHPFPM'
[www]
user = www-data
group = www-data
listen = /var/run/php/php8.2-fpm.sock
listen.owner = www-data
listen.group = www-data
pm = dynamic
pm.max_children = 20
pm.start_servers = 5
pm.min_spare_servers = 3
pm.max_spare_servers = 10
pm.max_requests = 500
php_admin_value[upload_max_filesize] = 20M
php_admin_value[post_max_size] = 25M
php_admin_value[memory_limit] = 256M
PHPFPM

systemctl restart php${PHP_VERSION}-fpm

# Configure Supervisor for queue worker
echo "[9/10] Setting up queue worker..."
cat > /etc/supervisor/conf.d/$APP_NAME-worker.conf << 'SUPERVISOR'
[program:gold-digger-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/gold-digger/artisan queue:work --sleep=3 --tries=3 --max-time=3600
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=2
redirect_stderr=true
stdout_logfile=/var/www/gold-digger/storage/logs/worker.log
stopwaitsecs=3600
SUPERVISOR

supervisorctl reread
supervisorctl update

# Setup cron for Laravel scheduler
echo "[10/10] Setting up Laravel scheduler..."
(crontab -u www-data -l 2>/dev/null || true; echo "* * * * * cd /var/www/gold-digger && php artisan schedule:run >> /dev/null 2>&1") | crontab -u www-data -

# Save credentials
echo ""
echo "================================================"
echo "  SERVER SETUP COMPLETE!"
echo "================================================"
echo ""
echo "Database credentials (SAVE THESE!):"
echo "  DB_DATABASE=$DB_NAME"
echo "  DB_USERNAME=$DB_USER"
echo "  DB_PASSWORD=$DB_PASS"
echo ""
echo "Next steps:"
echo "  1. Clone your repo: cd $APP_DIR && git clone YOUR_REPO_URL ."
echo "  2. Copy .env.example to .env and configure"
echo "  3. Run: composer install --no-dev --optimize-autoloader"
echo "  4. Run: npm ci && npm run build"
echo "  5. Run: php artisan key:generate"
echo "  6. Run: php artisan migrate"
echo "  7. Run: php artisan storage:link"
echo "  8. SSL: sudo certbot --nginx -d $DOMAIN"
echo ""
echo "Credentials saved to: /root/.gold-digger-credentials"

cat > /root/.gold-digger-credentials << CREDS
DB_DATABASE=$DB_NAME
DB_USERNAME=$DB_USER
DB_PASSWORD=$DB_PASS
CREDS
chmod 600 /root/.gold-digger-credentials

echo "Done!"
