FROM php:8.4-fpm-alpine

# ── System dependencies, Nginx, Supervisor, ImageMagick & OCR ────────────────
RUN apk add --no-cache \
        bash \
        curl \
        nginx \
        supervisor \
        postgresql-client \
        tesseract-ocr \
        tesseract-ocr-data-eng \
        ghostscript \
        imagemagick \
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

# ── Composer ──────────────────────────────────────────────────────────────────
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Match bind-mount file ownership on the host (typical WSL/Linux dev UID 1000).
RUN addgroup -g 1000 -S appuser \
    && adduser -u 1000 -D -S -G appuser appuser \
    && sed -i 's/^user = www-data/user = appuser/' /usr/local/etc/php-fpm.d/www.conf \
    && sed -i 's/^group = www-data/group = appuser/' /usr/local/etc/php-fpm.d/www.conf

WORKDIR /var/www/html

# ── PHP, Nginx & Supervisor Configs ──────────────────────────────────────────
COPY docker/php/custom.ini /usr/local/etc/php/conf.d/custom.ini
COPY docker/nginx/default.conf /etc/nginx/http.d/default.conf
COPY docker/supervisord.conf /etc/supervisord.conf

# ── Startup script ────────────────────────────────────────────────────────────
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 80 9000

ENTRYPOINT ["entrypoint.sh"]
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisord.conf"]