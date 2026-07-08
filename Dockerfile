# syntax=docker/dockerfile:1.7

# ============================================================================
# BUILD STAGE — install composer + npm dependencies, build assets
# ============================================================================
FROM php:8.3-fpm-alpine AS build

# Install build deps
RUN apk add --no-cache \
    git curl unzip libzip-dev libpng-dev libjpeg-turbo-dev \
    freetype-dev libxml2-dev oniguruma-dev nodejs npm

# PHP extensions
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
        pdo_mysql zip gd pcntl exif

# Composer
COPY --from=composer:2.7 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Install PHP deps first (better caching)
COPY composer.json composer.lock* ./
RUN composer install --no-dev --no-scripts --no-autoloader --no-interaction || \
    composer install --no-dev --no-scripts --no-autoloader --no-interaction --ignore-platform-reqs

# Install npm deps (use install instead of ci since no lockfile yet)
COPY package.json package-lock.json* ./
RUN npm install

COPY . .

# Generate autoloader + build assets
RUN composer dump-autoload --no-scripts --optimize
RUN npm run build

# ============================================================================
# RUNTIME STAGE — minimal image with only runtime deps
# ============================================================================
FROM php:8.3-fpm-alpine AS runtime

RUN apk add --no-cache \
    libzip-dev libpng-dev libjpeg-turbo-dev freetype-dev libxml2-dev oniguruma-dev \
    zlib-dev \
    nginx supervisor curl \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
        pdo_mysql zip gd pcntl exif opcache

# Configure PHP for production
RUN echo "opcache.enable=1" >> /usr/local/etc/php/conf.d/opcache-recommended.ini \
    && echo "opcache.memory_consumption=256" >> /usr/local/etc/php/conf.d/opcache-recommended.ini \
    && echo "opcache.max_accelerated_files=20000" >> /usr/local/etc/php/conf.d/opcache-recommended.ini

WORKDIR /app

# Copy app from build stage (with vendor + public/build already built)
COPY --from=build /app /app

# Copy configs
COPY docker/nginx.conf /etc/nginx/http.d/default.conf
COPY docker/supervisord.conf /etc/supervisord.conf
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

# Storage permissions
RUN mkdir -p /app/storage/app/public \
        /app/storage/framework/cache \
        /app/storage/framework/sessions \
        /app/storage/framework/views \
        /app/storage/logs \
        /app/bootstrap/cache \
        /var/log/supervisor \
        /run \
    && chown -R www-data:www-data /app/storage /app/bootstrap/cache \
    || chown -R nobody:nobody /app/storage /app/bootstrap/cache

EXPOSE 80

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["supervisord", "-c", "/etc/supervisord.conf"]
