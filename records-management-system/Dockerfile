FROM php:8.4-fpm-alpine

# ── System dependencies & Nginx & Supervisor ────────────────────────────────
RUN apk add --no-cache \
        bash \
        curl \
        nginx \
        supervisor \
        libpng-dev \
        libjpeg-turbo-dev \
        libwebp-dev \
        freetype-dev \
        libxml2-dev \
        oniguruma-dev \
        zip \
        unzip \
        postgresql-client \
        postgresql-dev \
    && docker-php-ext-configure gd \
        --with-freetype \
        --with-jpeg \
        --with-webp \
    && docker-php-ext-install \
        pdo_pgsql \
        pgsql \
        mbstring \
        exif \
        pcntl \
        bcmath \
        gd \
    && mkdir -p /run/nginx /var/log/supervisor /var/log/nginx

# ── Composer ──────────────────────────────────────────────────────────────────
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# ── Nginx & Supervisor Configs ────────────────────────────────────────────────
COPY docker/nginx/default.conf /etc/nginx/http.d/default.conf
COPY docker/supervisord.conf /etc/supervisord.conf

# ── Startup script ────────────────────────────────────────────────────────────
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 80 9000

ENTRYPOINT ["entrypoint.sh"]
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisord.conf"]