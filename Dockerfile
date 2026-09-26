# syntax=docker/dockerfile:1

# ---------------------------------------------------------------------------
# Stage 1: build front-end assets (Vite -> public/build, service worker -> public/sw.js)
# ---------------------------------------------------------------------------
FROM node:20.20-alpine AS assets

WORKDIR /app

COPY package.json package-lock.json ./
RUN --mount=type=cache,target=/root/.npm \
    npm ci

COPY vite.config.js ./
COPY resources ./resources
COPY public ./public
RUN npm run build

# ---------------------------------------------------------------------------
# Stage 2: PHP dependencies
# ---------------------------------------------------------------------------
FROM composer:2.10 AS vendor

WORKDIR /app

COPY composer.json composer.lock ./
RUN --mount=type=cache,target=/tmp/composer-cache \
    COMPOSER_CACHE_DIR=/tmp/composer-cache composer install \
        --no-dev \
        --no-interaction \
        --no-scripts \
        --no-autoloader \
        --prefer-dist \
        --ignore-platform-reqs

# ---------------------------------------------------------------------------
# Stage 3: Ollama binary (copied into the runtime image below)
# ---------------------------------------------------------------------------
FROM ollama/ollama:0.34.4 AS ollama-bin

# ---------------------------------------------------------------------------
# Stage 4: runtime (PHP 8.2 + Apache + Ollama, single container)
# ---------------------------------------------------------------------------
FROM php:8.2.33-apache AS app

ENV APACHE_DOCUMENT_ROOT=/var/www/html/public \
    COMPOSER_ALLOW_SUPERUSER=1 \
    OLLAMA_MODELS=/var/lib/ollama \
    OLLAMA_HOST=127.0.0.1:11434

COPY --from=ollama-bin /bin/ollama /usr/local/bin/ollama
RUN mkdir -p "$OLLAMA_MODELS"

# PHP extensions: pdo_mysql (DB), gd (PDF image embedding), intl, bcmath, zip, opcache.
# mbstring, openssl and zlib (gzcompress) ship with the base image.
# Remove the installer + apt lists afterward — only the compiled .so files are needed at runtime.
ADD --chmod=0755 https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/
RUN install-php-extensions pdo_mysql gd intl bcmath zip opcache pcntl \
    && rm -rf /usr/local/bin/install-php-extensions /var/lib/apt/lists/* /tmp/*

RUN sed -ri 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
    && sed -ri 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf \
    && a2enmod rewrite headers \
    && mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

COPY docker/php.ini "$PHP_INI_DIR/conf.d/zz-app.ini"
COPY --from=vendor /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY --from=vendor /app/vendor ./vendor
COPY . .
COPY --from=assets /app/public/build ./public/build
COPY --from=assets /app/public/sw.js ./public/sw.js

RUN composer dump-autoload --optimize --no-dev --classmap-authoritative \
    && mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R ug+rwX storage bootstrap/cache

COPY --chmod=0755 docker/entrypoint.sh /usr/local/bin/app-entrypoint

EXPOSE 80

ENTRYPOINT ["app-entrypoint"]
CMD ["apache2-foreground"]
