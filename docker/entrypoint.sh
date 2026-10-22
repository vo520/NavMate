#!/bin/sh
set -e

cd /var/www/html

# First boot: create .env from the example template
if [ ! -f .env ]; then
    cp .env.example .env
fi

# Generate an application key if none is present yet
if ! grep -q '^APP_KEY=base64:' .env; then
    php artisan key:generate --force
fi

# Ensure writable runtime directories exist
mkdir -p \
    storage/app/public \
    storage/app/private \
    storage/framework/cache \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    bootstrap/cache

chown -R www-data:www-data storage bootstrap/cache

# Expose uploaded files (storage/app/public) through public/storage
php artisan storage:link 2>/dev/null || true

exec apache2-foreground
