# syntax=docker/dockerfile:1
# SIGPE Reporting Service — PHP 8.5 FPM (versions : docs/technical/MATRICE_VERSIONS.md)
FROM php:8.5.12-fpm-bookworm AS base

RUN apt-get update && apt-get install -y --no-install-recommends \
        git unzip libpq-dev libzip-dev libicu-dev libonig-dev \
    && docker-php-ext-install pdo_pgsql pgsql bcmath intl zip pcntl opcache \
    && rm -rf /var/lib/apt/lists/*

# --- build : application + dependances composer (prod) ---
FROM base AS app
WORKDIR /var/www/html
COPY --from=composer:2.11.2 /usr/bin/composer /usr/bin/composer
COPY . .
RUN groupadd -g 1000 sigpe && useradd -u 1000 -g sigpe -m sigpe \
    && composer install --no-dev --prefer-dist --optimize-autoloader \
    && chown -R sigpe:sigpe /var/www/html \
    && chmod -R ug+rw storage bootstrap/cache

# --- runtime : memes extensions PHP que le stage de build ---
FROM base AS runtime
WORKDIR /var/www/html
RUN groupadd -g 1000 sigpe && useradd -u 1000 -g sigpe -m sigpe
COPY --from=app --chown=sigpe:sigpe /var/www/html .
COPY docker/php/opcache.ini /usr/local/etc/php/conf.d/sigpe-opcache.ini
COPY docker/php/www.conf /usr/local/etc/php-fpm.d/zz-sigpe-www.conf

USER sigpe
EXPOSE 9000

HEALTHCHECK --interval=30s --timeout=3s --start-period=10s --retries=3 \
    CMD php -r "exit((int)!@fsockopen('127.0.0.1', 9000));"

CMD ["php-fpm"]
