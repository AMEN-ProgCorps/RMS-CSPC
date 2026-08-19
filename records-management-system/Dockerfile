FROM php:8.4-fpm-alpine

# ── System dependencies, Nginx, Supervisor, ImageMagick & OCR ────────────────
RUN apk add --no-cache \
        bash \
        curl \
        git \
        nginx \
        supervisor \
        libpng-dev \
        libzip-dev \
        icu-dev \
        libjpeg-turbo-dev \
        libwebp-dev \
        freetype-dev \
        libxml2-dev \
        oniguruma-dev \
        zip \
        unzip \
        postgresql-client \
        postgresql-dev \
        tesseract-ocr \
        tesseract-ocr-data-eng \
        ghostscript \
        imagemagick \
        imagemagick-dev \
    && docker-php-ext-configure gd \
        --with-freetype \
        --with-jpeg \
        --with-webp \
    && docker-php-ext-install -j$(nproc) \
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
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS linux-headers \
    && git clone --depth 1 https://github.com/Imagick/imagick.git /tmp/imagick \
    && cd /tmp/imagick \
    && phpize \
    && ./configure \
    && make -j$(nproc) \
    && make install \
    && docker-php-ext-enable imagick \
    && rm -rf /tmp/imagick \
    && find /etc -name 'policy.xml' -exec sed -i \
        -e 's/rights="none" pattern="PDF"/rights="read|write" pattern="PDF"/I' \
        -e 's/rights="none" pattern="{PS,PDF,XPS}"/rights="read|write" pattern="{PS,PDF,XPS}"/I' \
        -e 's/pattern="{GIF,JPEG,PNG,WEBP}"/pattern="{GIF,JPEG,PNG,WEBP,PDF,PS}"/I' \
        {} \; \
    && apk del .build-deps \
    && mkdir -p /run/nginx /var/log/supervisor /var/log/nginx

# ── Composer ──────────────────────────────────────────────────────────────────
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

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