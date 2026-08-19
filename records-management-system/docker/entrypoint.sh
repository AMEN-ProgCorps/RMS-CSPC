#!/bin/bash
set -e

echo "──────────────────────────────────────────"
echo "  RMS-CSPC — Container Startup"
echo "──────────────────────────────────────────"

# ── Ensure Nginx run dir & Laravel storage directories exist with permissions ─
mkdir -p /run/nginx /var/log/nginx /var/log/supervisor
mkdir -p storage/framework/views storage/framework/sessions storage/framework/cache/data storage/logs bootstrap/cache public/chatify/uploads public/chatify/storage
chmod -R 777 storage bootstrap/cache public/chatify/uploads public/chatify/storage

# ── Ensure .env file exists ──────────────────────────────────────────────────
if [ ! -f ".env" ]; then
    if [ -f ".env.docker" ]; then
        echo "  ℹ  No .env found — copying from .env.docker..."
        cp .env.docker .env
    elif [ -f ".env.example" ]; then
        echo "  ℹ  No .env found — copying from .env.example..."
        cp .env.example .env
    fi
fi

# ── Install / sync PHP dependencies ─────────────────────────────────────────
echo "[1/5] Checking PHP dependencies..."
composer install --no-interaction --prefer-dist --optimize-autoloader

# ── Ensure APP_KEY exists ────────────────────────────────────────────────────
if [ -z "$APP_KEY" ]; then
    echo "  Generating application key..."
    php artisan key:generate --force
fi

# ── Google Drive credentials check ───────────────────────────────────────────
if [ ! -f "storage/app/google-drive-service-account.json" ]; then
    echo "  ℹ  Google Drive Service Account key not found at storage/app/google-drive-service-account.json"
    echo "     To enable Google Drive cloud storage, place your JSON key file at that path."
fi

# ── Wait for Database & Run Migrations ───────────────────────────────────────
echo "[2/5] Waiting for database connection..."
DB_HOST_TARGET="${DB_HOST:-db}"
DB_PORT_TARGET="${DB_PORT:-5432}"
DB_USER_TARGET="${DB_USERNAME:-adminrms}"
DB_NAME_TARGET="${DB_DATABASE:-rms}"

until pg_isready -h "$DB_HOST_TARGET" -p "$DB_PORT_TARGET" -U "$DB_USER_TARGET" -d "$DB_NAME_TARGET" -t 3 >/dev/null 2>&1; do
    echo "  Database ($DB_HOST_TARGET:$DB_PORT_TARGET) is not ready yet — retrying in 3s..."
    sleep 3
done
echo "  Database is ready."

echo "  Running database migrations..."
php artisan migrate --force
echo "  Migrations OK."

# ── Storage link ─────────────────────────────────────────────────────────────
echo "[3/5] Creating storage symlink..."
php artisan storage:link --force 2>/dev/null || true

# ── Optimize / Clear Cache ───────────────────────────────────────────────────
if [ "$APP_ENV" = "production" ]; then
    echo "[4/5] Caching config, routes, and views for production..."
    php artisan config:cache
    php artisan route:cache
    php artisan view:cache
else
    echo "[4/5] Clearing stale caches for development..."
    php artisan optimize:clear
fi

echo "[5/5] Starting services via Supervisor..."
echo "──────────────────────────────────────────"

exec "$@"
