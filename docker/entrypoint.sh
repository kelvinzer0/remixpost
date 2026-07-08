#!/bin/sh
set -e

cd /app

# Create .env from .env.example if it doesn't exist (for first run)
if [ ! -f .env ]; then
    if [ -f .env.example ]; then
        echo "==> Creating .env from .env.example..."
        cp .env.example .env
    else
        echo "==> Creating empty .env file..."
        touch .env
    fi
fi

# Generate APP_KEY if not set
if ! grep -q "^APP_KEY=" .env || grep -q "^APP_KEY=$" .env; then
    echo "==> Generating APP_KEY..."
    php artisan key:generate --force
fi

# Ensure storage directories exist with correct permissions
echo "==> Setting storage permissions..."
mkdir -p storage/app/public \
         storage/framework/cache \
         storage/framework/sessions \
         storage/framework/views \
         storage/logs \
         bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache 2>/dev/null || \
chown -R nobody:nobody storage bootstrap/cache 2>/dev/null || true
chmod -R 775 storage bootstrap/cache

# Wait for MySQL
echo "==> Waiting for MySQL..."
until php -r "new PDO('mysql:host=${DB_HOST};port=${DB_PORT};dbname=${DB_DATABASE}', '${DB_USERNAME}', '${DB_PASSWORD}');" 2>/dev/null; do
    sleep 1
done
echo "    MySQL is up."

# Run migrations (creates cache table + all other tables)
echo "==> Running migrations..."
php artisan migrate --force

# Storage link (ignore error if already linked)
php artisan storage:link 2>/dev/null || true

# Cache config & routes (production optimizations)
if [ "$APP_ENV" = "production" ]; then
    echo "==> Caching config and routes..."
    # Export APP_KEY from .env so config:cache picks it up
    # (docker env_file may have empty APP_KEY that overrides .env)
    export APP_KEY=$(grep "^APP_KEY=" .env | cut -d'=' -f2-)
    php artisan config:cache
    php artisan route:cache
    php artisan view:cache
fi

echo "==> Starting services..."
exec "$@"
