#!/bin/sh
set -e

cd /var/www

# Copy public files to the shared volume if index.php is missing
if [ ! -f /var/www/public/index.php ]; then
    echo "Populating public directory..."
    cp -r /var/www/public_build/* /var/www/public/ 2>/dev/null || true
fi

# Generate .env from environment variables if not present
if [ ! -f /var/www/.env ]; then
    echo "Generating .env from environment variables..."
    cat > /var/www/.env << ENVEOF
APP_NAME="${APP_NAME:-Workforce Compliance Platform}"
APP_ENV=${APP_ENV:-local}
APP_KEY=${APP_KEY:-base64:QojhT2erTe8uJUXXTS1NgYriLk1t6Nl3B/mLVQYZ9ng=}
APP_DEBUG=${APP_DEBUG:-true}
APP_URL=${APP_URL:-http://localhost:8000}
DB_CONNECTION=${DB_CONNECTION:-mysql}
DB_HOST=${DB_HOST:-db}
DB_PORT=${DB_PORT:-3306}
DB_DATABASE=${DB_DATABASE:-workforce_compliance}
DB_USERNAME=${DB_USERNAME:-wc_user}
DB_PASSWORD=${DB_PASSWORD:-wc_password}
CACHE_DRIVER=${CACHE_DRIVER:-file}
QUEUE_CONNECTION=${QUEUE_CONNECTION:-database}
SESSION_DRIVER=${SESSION_DRIVER:-database}
FILESYSTEM_DISK=${FILESYSTEM_DISK:-local}
LOG_CHANNEL=${LOG_CHANNEL:-stack}
ENVEOF
fi

# Wait for database to be ready
echo "Waiting for database connection..."
max_tries=30
count=0
while ! mysqladmin ping -h"${DB_HOST:-db}" -u"${DB_USERNAME:-wc_user}" -p"${DB_PASSWORD:-wc_password}" --silent 2>/dev/null; do
    count=$((count + 1))
    if [ $count -ge $max_tries ]; then
        echo "Warning: Database may not be ready after $max_tries attempts, proceeding..."
        break
    fi
    echo "Attempt $count/$max_tries - waiting for database..."
    sleep 2
done

echo "Database connection established!"

# Generate app key if not set
php artisan key:generate --force --no-interaction 2>/dev/null || true

# Clear and rebuild config cache
php artisan config:clear 2>/dev/null || true

# Run migrations
echo "Running migrations..."
php artisan migrate --force --no-interaction 2>&1 || true

# Seed system data (roles, config metadata only - never business entities)
echo "Seeding system data..."
php artisan db:seed --force --no-interaction 2>&1 || true

# Create storage link
php artisan storage:link --force 2>/dev/null || true

# Set permissions
chown -R www-data:www-data /var/www/storage /var/www/bootstrap/cache 2>/dev/null || true
chmod -R 775 /var/www/storage /var/www/bootstrap/cache 2>/dev/null || true

echo "Application ready!"
exec "$@"
