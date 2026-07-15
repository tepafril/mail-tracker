#!/bin/sh
set -e

cd /var/www/html

# The storage/ tree lives on a named volume that may start empty — recreate the
# framework subdirs Laravel expects and make them writable by the FPM workers.
mkdir -p \
  storage/app/public \
  storage/framework/cache/data \
  storage/framework/sessions \
  storage/framework/views \
  storage/logs
chown -R www-data:www-data storage bootstrap/cache
chmod -R ug+rwX storage bootstrap/cache

# Package manifest + config/route/view caches (idempotent; safe in every container).
# Requires the env vars from the compose env_file to be present, which they are.
php artisan package:discover --ansi || true
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

exec "$@"
