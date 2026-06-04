#!/bin/bash
set -e

echo "──────────────────────────────────────────"
echo "  RMS-CSPC — Container Startup"
echo "──────────────────────────────────────────"

# ── Install / sync PHP dependencies ─────────────────────────────────────────
echo "[1/5] Running composer install..."
composer install --no-interaction --prefer-dist --optimize-autoloader

# ── Generate APP_KEY if not set ──────────────────────────────────────────────
if [ -z "$APP_KEY" ]; then
    echo ""
    echo "  ⚠  APP_KEY is not set."
    echo "     After startup, run once:"
    echo "       docker compose exec app php artisan key:generate"
    echo "     Then copy the generated key into .env.docker as APP_KEY=..."
    echo ""
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
echo "[4/5] Caching config & routes..."
php artisan config:cache
php artisan route:cache

echo "[5/5] Starting server..."
echo "──────────────────────────────────────────"

exec "$@"
