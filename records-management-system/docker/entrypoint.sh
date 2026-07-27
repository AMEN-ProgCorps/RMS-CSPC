#!/bin/bash
set -e

echo "──────────────────────────────────────────"
echo "  RMS-CSPC — Container Startup"
echo "──────────────────────────────────────────"

# ── Ensure Nginx run dir & Laravel storage directories exist with permissions ─
mkdir -p /run/nginx /var/log/nginx /var/log/supervisor
mkdir -p storage/framework/views storage/framework/sessions storage/framework/cache/data storage/logs bootstrap/cache public/chatify/uploads public/chatify/storage
chmod -R 777 storage bootstrap/cache public/chatify/uploads public/chatify/storage

# ── Install / sync PHP dependencies ─────────────────────────────────────────
echo "[1/5] Running composer install..."
if [ ! -f "vendor/autoload.php" ]; then
    composer install --no-interaction --prefer-dist --optimize-autoloader
else
    echo "  vendor/ already exists — skipping install."
fi

# ── Generate APP_KEY if not set ──────────────────────────────────────────────
if [ -z "$APP_KEY" ]; then
    echo ""
    echo "  ⚠  APP_KEY is not set."
    echo "     After startup, run once:"
    echo "       docker compose exec app php artisan key:generate"
    echo "     Then copy the generated key into .env.docker as APP_KEY=..."
    echo ""
fi

# ── Google Drive credentials warning ──────────────────────────────────────────
if [ ! -f "storage/app/google-drive-service-account.json" ]; then
    echo "  ℹ  Google Drive Service Account key not found at storage/app/google-drive-service-account.json"
    echo "     To enable Google Drive cloud storage, place your JSON key file at that path."
fi

# ── Wait for DB and run migrations ───────────────────────────────────────────
echo "[2/5] Waiting for database..."
until php artisan migrate --force 2>/dev/null; do
    echo "  DB not ready yet — retrying in 3s..."
    sleep 3
done
echo "  Migrations OK."

# ── Storage link ─────────────────────────────────────────────────────────────
echo "[3/5] Creating storage symlink..."
php artisan storage:link --force 2>/dev/null || true

# ── Cache ─────────────────────────────────────────────────────────────────────
echo "[4/5] Caching config..."
php artisan config:cache

echo "[5/5] Starting server..."
echo "──────────────────────────────────────────"

exec "$@"
