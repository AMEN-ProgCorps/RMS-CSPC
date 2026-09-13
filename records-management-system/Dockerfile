FROM php:8.4-fpm-bookworm

# ── System dependencies, Nginx, Supervisor, ImageMagick & PaddleOCR ───────────
RUN apt-get update && apt-get install -y --no-install-recommends \
        bash \
        curl \
        nginx \
        supervisor \
        postgresql-client \
        ghostscript \
        imagemagick \
        python3 \
        python3-pip \
        python3-venv \
        libgl1 \
        libglib2.0-0 \
        libgomp1 \
        libgfortran5 \
        && rm -rf /var/lib/apt/lists/* \
    && mkdir -p /run/nginx /var/log/supervisor /var/log/nginx

# ── PHP Extension Installer ───────────────────────────────────────────────────
COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/

# ── Install PHP Extensions ───────────────────────────────────────────────────
RUN install-php-extensions \
        pdo_pgsql \
        pgsql \
        mbstring \
        exif \
        pcntl \
        bcmath \
        gd \
        zip \
        intl \
        opcache \
        imagick

# ── Adjust ImageMagick PDF Policy ─────────────────────────────────────────────
RUN find /etc -name 'policy.xml' -exec sed -i \
        -e 's/rights="none" pattern="PDF"/rights="read|write" pattern="PDF"/I' \
        -e 's/rights="none" pattern="{PS,PDF,XPS}"/rights="read|write" pattern="{PS,PDF,XPS}"/I' \
        -e 's/pattern="{GIF,JPEG,PNG,WEBP}"/pattern="{GIF,JPEG,PNG,WEBP,PDF,PS}"/I' \
        {} \;

# ── PaddleOCR (CPU) in isolated venv ──────────────────────────────────────────
# Models must live in a shared path readable by PHP-FPM (appuser), not /root.
COPY scripts/ocr/requirements.txt /opt/paddle-requirements.txt
RUN python3 -m venv /opt/paddle-venv \
    && /opt/paddle-venv/bin/pip install --no-cache-dir --upgrade pip \
    && /opt/paddle-venv/bin/pip install --no-cache-dir -r /opt/paddle-requirements.txt \
    && mkdir -p /opt/paddleocr \
    && HOME=/opt/paddleocr /opt/paddle-venv/bin/python -c \
        "from paddleocr import PaddleOCR; PaddleOCR(use_angle_cls=True, lang='en', show_log=False, use_gpu=False)"

ENV OCR_ENGINE=paddle \
    OCR_PYTHON=/opt/paddle-venv/bin/python \
    OCR_PADDLE_SCRIPT=/var/www/html/scripts/ocr/paddle_ocr.py \
    OCR_TIMEOUT=120 \
    PADDLEOCR_HOME=/opt/paddleocr

# ── Composer ──────────────────────────────────────────────────────────────────
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Match bind-mount file ownership on the host (typical WSL/Linux dev UID 1000).
RUN groupadd -g 1000 appuser \
    && useradd -u 1000 -g appuser -M -s /usr/sbin/nologin appuser \
    && chown -R appuser:appuser /opt/paddleocr \
    && sed -i 's/^user = www-data/user = appuser/' /usr/local/etc/php-fpm.d/www.conf \
    && sed -i 's/^group = www-data/group = appuser/' /usr/local/etc/php-fpm.d/www.conf

WORKDIR /var/www/html

# ── PHP, Nginx & Supervisor Configs ──────────────────────────────────────────
COPY docker/php/custom.ini /usr/local/etc/php/conf.d/custom.ini
RUN rm -f /etc/nginx/sites-enabled/default
COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf
COPY docker/supervisord.conf /etc/supervisord.conf

# ── Startup script ────────────────────────────────────────────────────────────
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 80 9000

ENTRYPOINT ["entrypoint.sh"]
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisord.conf"]
