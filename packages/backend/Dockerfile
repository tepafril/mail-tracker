# syntax=docker/dockerfile:1.7
#
# Production image for the SMOH Mail Tracker Laravel backend.
# Two build targets consumed by docker-compose:
#   - target: app  -> PHP-FPM 8.4 with the full app (used by app/horizon/scheduler)
#   - target: web  -> Caddy serving public/ + reverse-proxying PHP (auto TLS)

############################################
# 1. Composer dependencies (no dev, no scripts)
############################################
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install \
      --no-dev \
      --no-interaction \
      --prefer-dist \
      --no-scripts \
      --no-autoloader

############################################
# 2. Front-end assets (Vite build -> public/build)
############################################
FROM node:22-bookworm-slim AS assets
WORKDIR /app
COPY package.json package-lock.json* ./
RUN if [ -f package-lock.json ]; then npm ci; else npm install; fi
COPY vite.config.js ./
COPY resources ./resources
RUN npm run build

############################################
# 3. PHP base with the extensions this app needs
############################################
FROM php:8.4-fpm-bookworm AS base
RUN apt-get update && apt-get install -y --no-install-recommends \
      libicu-dev libzip-dev libpq-dev unzip \
    && docker-php-ext-configure intl \
    && docker-php-ext-install -j"$(nproc)" \
      pdo_mysql pdo_pgsql bcmath pcntl intl zip opcache \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apt-get purge -y --auto-remove \
    && rm -rf /var/lib/apt/lists/*
COPY docker/php-prod.ini /usr/local/etc/php/conf.d/zz-prod.ini

############################################
# 4. Final application image (PHP-FPM)
############################################
FROM base AS app
WORKDIR /var/www/html

# Composer binary (reused from the vendor stage image) for autoloader dump
COPY --from=vendor /usr/bin/composer /usr/local/bin/composer

# Application source + vendored deps + built assets
COPY . .
COPY --from=vendor /app/vendor ./vendor
COPY --from=assets /app/public/build ./public/build

# Optimized autoloader (package discovery deferred to entrypoint, when env is present)
RUN composer dump-autoload --optimize --no-dev --no-scripts \
    && chown -R www-data:www-data storage bootstrap/cache

COPY docker/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint

ENTRYPOINT ["entrypoint"]
CMD ["php-fpm"]

############################################
# 5. Web server (Caddy: static + FastCGI + auto TLS)
############################################
FROM caddy:2-alpine AS web
COPY --from=app /var/www/html/public /var/www/html/public
COPY docker/Caddyfile /etc/caddy/Caddyfile
