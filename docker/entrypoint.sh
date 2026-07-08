#!/bin/sh
set -e

cd /app

# Generate APP_KEY if not set
if [ -z "$APP_KEY" ] || [ "$APP_KEY" = "" ]; then
    echo "==> Generating APP_KEY..."
    php artisan key:generate --force
fi

# Wait for MySQL
echo "==> Waiting for MySQL..."
until php -r "new PDO('mysql:host=${DB_HOST};port=${DB_PORT};dbname=${DB_DATABASE}', '${DB_USERNAME}', '${DB_PASSWORD}');" 2>/dev/null; do
    sleep 1
done
echo "    MySQL is up."

# Run migrations
echo "==> Running migrations..."
php artisan migrate --force

# Cache config & routes (production optimizations)
if [ "$APP_ENV" = "production" ]; then
    echo "==> Caching config and routes..."
    php artisan config:cache
    php artisan route:cache
    php artisan view:cache
fi

# Storage link
php artisan storage:link 2>/dev/null || true

echo "==> Starting services..."
exec "$@"
