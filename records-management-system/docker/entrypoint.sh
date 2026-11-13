#!/bin/bash
set -e

echo "──────────────────────────────────────────"
echo "  RMS-CSPC — Container Startup"
echo "──────────────────────────────────────────"

# ── Ensure Nginx run dir & Laravel storage directories exist with permissions ─
mkdir -p /run/nginx /var/log/nginx /var/log/supervisor
mkdir -p storage/framework/views storage/framework/sessions storage/framework/cache/data storage/logs storage/app/manuals resources/manuals bootstrap/cache public/chatify/uploads public/chatify/storage public/messify/uploads public/messify/storage
chmod -R 777 storage bootstrap/cache public/chatify/uploads public/chatify/storage public/messify/uploads public/messify/storage resources/manuals 2>/dev/null || true
chown -R appuser:appuser storage resources/manuals 2>/dev/null || true
# Named volume at storage/framework/views (see docker-compose) — avoid /tmp and bind-mount utime failures.
export VIEW_COMPILED_PATH="${VIEW_COMPILED_PATH:-/var/www/html/storage/framework/views}"
mkdir -p "$VIEW_COMPILED_PATH"
chmod 777 "$VIEW_COMPILED_PATH" || true

# ── Tune PHP-FPM Pool Worker Limits to Prevent Saturation ──────────────────
if [ -f /usr/local/etc/php-fpm.d/www.conf ]; then
    sed -i 's/^pm.max_children = .*/pm.max_children = 20/' /usr/local/etc/php-fpm.d/www.conf || true
    sed -i 's/^pm.start_servers = .*/pm.start_servers = 4/' /usr/local/etc/php-fpm.d/www.conf || true
    sed -i 's/^pm.min_spare_servers = .*/pm.min_spare_servers = 2/' /usr/local/etc/php-fpm.d/www.conf || true
    sed -i 's/^pm.max_spare_servers = .*/pm.max_spare_servers = 10/' /usr/local/etc/php-fpm.d/www.conf || true
fi

# ── Ensure Git is available for rolling updates & repository tracking ─────────
if ! command -v git >/dev/null 2>&1; then
    if command -v apk >/dev/null 2>&1; then
        apk add --no-cache git >/dev/null 2>&1 || true
    elif command -v apt-get >/dev/null 2>&1; then
        apt-get update -qq && apt-get install -y -qq git >/dev/null 2>&1 || true
    fi
fi
if command -v git >/dev/null 2>&1; then
    git config --system --add safe.directory "*" >/dev/null 2>&1 || true
    git config --global --add safe.directory "*" >/dev/null 2>&1 || true
    su -s /bin/bash appuser -c 'git config --global --add safe.directory "*"' >/dev/null 2>&1 || true
    for gitdir in "/var/www/repo/.git" "/var/www/html/.git"; do
        if [ -d "$gitdir" ]; then
            chmod -R ugo+rwX "$gitdir" 2>/dev/null || true
        fi
    done
fi

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
chmod -R 777 storage bootstrap/cache public/messify/uploads public/messify/storage

# Compiled Blade views: artisan (root) can leave root-owned files in VIEW_COMPILED_PATH.
# chmod 777 is not enough — PHP-FPM (appuser) can overwrite them but utime() still
# fails with "touch(): Utime failed: Operation not permitted".
# Wipe compiled files so the first request recreates them owned by appuser.
if [ -d "$VIEW_COMPILED_PATH" ]; then
    find "$VIEW_COMPILED_PATH" -type f -delete 2>/dev/null || true
fi
mkdir -p "$VIEW_COMPILED_PATH"
chmod 777 "$VIEW_COMPILED_PATH" || true
if [ -d storage/framework/views ]; then
    find storage/framework/views -type f -name '*.php' -delete 2>/dev/null || true
    chmod -R 777 storage/framework/views || true
fi
if id appuser >/dev/null 2>&1; then
    usermod -aG root appuser 2>/dev/null || true
    chown -R appuser:appuser "$VIEW_COMPILED_PATH" storage bootstrap/cache 2>/dev/null || true
fi
if [ -S /var/run/docker.sock ]; then
    chmod 666 /var/run/docker.sock 2>/dev/null || true
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

# Masterlist PDF export uses headless Chromium so the download matches print preview.
CHROME_BIN=""
for candidate in /usr/bin/chromium /usr/bin/chromium-browser /usr/lib/chromium/chromium; do
    if [ -x "$candidate" ] || [ -f "$candidate" ]; then
        CHROME_BIN="$candidate"
        break
    fi
done
if [ -z "$CHROME_BIN" ] && command -v apt-get >/dev/null 2>&1; then
    echo "  ⚠  Chromium missing — installing so Document Masterlist PDF matches preview..."
    apt-get update -qq \
        && DEBIAN_FRONTEND=noninteractive apt-get install -y -qq \
            chromium chromium-common fonts-liberation fonts-liberation2 fonts-dejavu-core \
        || echo "  ⚠  Chromium install failed — masterlist PDF will use Dompdf (same HTML)."
    for candidate in /usr/bin/chromium /usr/bin/chromium-browser /usr/lib/chromium/chromium; do
        if [ -x "$candidate" ] || [ -f "$candidate" ]; then
            CHROME_BIN="$candidate"
            break
        fi
    done
fi
if [ -n "$CHROME_BIN" ]; then
    echo "  ✓  Chromium OK for masterlist PDF export ($CHROME_BIN)"
    # PHP-FPM (appuser) must find the binary — export for children.
    export CHROMIUM_PATH="$CHROME_BIN"
    export PUPPETEER_EXECUTABLE_PATH="$CHROME_BIN"
    # Persist for PHP-FPM workers that do not inherit this shell.
    mkdir -p /etc/php || true
    printf 'CHROMIUM_PATH=%s\nPUPPETEER_EXECUTABLE_PATH=%s\n' "$CHROME_BIN" "$CHROME_BIN" \
        > /var/www/html/storage/app/chromium.env 2>/dev/null || true
else
    echo "  ⚠  Chromium still missing — masterlist PDF uses Dompdf with the same preview HTML."
fi

echo "[5/5] Starting services via Supervisor..."
echo "──────────────────────────────────────────"

exec "$@"
