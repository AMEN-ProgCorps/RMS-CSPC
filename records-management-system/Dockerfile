FROM php:8.4-fpm-alpine

# ── System dependencies ───────────────────────────────────────────────────────
RUN apk add --no-cache \
        bash \
        curl \
        libpng-dev \
        libxml2-dev \
        oniguruma-dev \
        zip \
        unzip \
        postgresql-client \
        postgresql-dev \
    && docker-php-ext-install \
        pdo_pgsql \
        pgsql \
        mbstring \
        exif \
        pcntl \
        bcmath \
        gd

# ── Composer ──────────────────────────────────────────────────────────────────
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# ── Startup script ────────────────────────────────────────────────────────────
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 8000

ENTRYPOINT ["entrypoint.sh"]
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
