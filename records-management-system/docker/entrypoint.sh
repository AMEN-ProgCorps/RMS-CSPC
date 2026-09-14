#!/bin/bash
set -e

echo "──────────────────────────────────────────"
echo "  RMS-CSPC — Container Startup"
echo "──────────────────────────────────────────"

# ── Ensure Nginx run dir & Laravel storage directories exist with permissions ─
mkdir -p /run/nginx /var/log/nginx /var/log/supervisor
mkdir -p storage/framework/views storage/framework/sessions storage/framework/cache/data storage/logs bootstrap/cache public/chatify/uploads public/chatify/storage /tmp/laravel-views
chmod -R 777 storage bootstrap/cache public/chatify/uploads public/chatify/storage /tmp/laravel-views
export VIEW_COMPILED_PATH="${VIEW_COMPILED_PATH:-/tmp/laravel-views}"

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

# ── Clean stale cache files ──────────────────────────────────────────────────
rm -f bootstrap/cache/*.php

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

# Artisan/migrate run as root and may create storage files the FPM pool cannot write.
chmod -R 777 storage bootstrap/cache public/chatify/uploads public/chatify/storage

# Compiled Blade views: artisan (root) can leave root-owned files in VIEW_COMPILED_PATH;
# PHP-FPM runs as appuser and then fails on touch() with "Operation not permitted".
mkdir -p "$VIEW_COMPILED_PATH"
chmod -R 777 "$VIEW_COMPILED_PATH" || true
if id appuser >/dev/null 2>&1; then
    chown -R appuser:appuser "$VIEW_COMPILED_PATH" 2>/dev/null || true
fi

# PaddleOCR model cache must be writable by PHP-FPM (appuser).
mkdir -p /opt/paddleocr /tmp/paddleocr
if [ -d /root/.paddleocr ] && [ ! -e /opt/paddleocr/.paddleocr ]; then
    cp -a /root/.paddleocr /opt/paddleocr/.paddleocr || true
fi
if id appuser >/dev/null 2>&1; then
    chown -R appuser:appuser /opt/paddleocr /tmp/paddleocr 2>/dev/null || chmod -R a+rwX /opt/paddleocr /tmp/paddleocr || true
else
    chmod -R a+rwX /opt/paddleocr /tmp/paddleocr || true
fi

# Fail loudly in logs if the deploy image is missing the OCR venv (common when
# an old image is used, or PHP runs outside the Dockerfile app image).
if [ ! -x /opt/paddle-venv/bin/python ]; then
    echo "  ⚠  OCR Python missing at /opt/paddle-venv/bin/python — DRF/DRR OCR will fail."
    echo "     Rebuild/redeploy the app image from the current Dockerfile."
elif ! /opt/paddle-venv/bin/python -c "import paddleocr" >/dev/null 2>&1; then
    echo "  ⚠  paddleocr package missing in /opt/paddle-venv — DRF/DRR OCR will fail."
else
    echo "  ✓  PaddleOCR venv OK"
fi

echo "[5/5] Starting services via Supervisor..."
echo "──────────────────────────────────────────"

exec "$@"
