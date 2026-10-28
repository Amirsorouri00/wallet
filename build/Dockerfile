FROM php:8.3-cli-bookworm AS extensions

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        libicu-dev \
        libonig-dev \
        libpq-dev \
        libxml2-dev \
    && docker-php-ext-install -j"$(nproc)" \
        dom \
        intl \
        mbstring \
        pcntl \
        pdo_pgsql \
        xml \
        xmlwriter \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html

FROM extensions AS dependencies

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
RUN apt-get update \
    && apt-get install -y --no-install-recommends unzip \
    && rm -rf /var/lib/apt/lists/*

COPY composer.json composer.lock ./
RUN composer install \
    --no-interaction \
    --no-progress \
    --no-scripts \
    --prefer-dist

COPY . .
RUN composer dump-autoload \
    --classmap-authoritative \
    --no-interaction

FROM php:8.3-cli-bookworm AS runtime

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        libicu72 \
        libonig5 \
        libpq5 \
        libxml2 \
    && rm -rf /var/lib/apt/lists/*

COPY --from=extensions /usr/local/lib/php/extensions/ /usr/local/lib/php/extensions/
COPY --from=extensions /usr/local/etc/php/conf.d/ /usr/local/etc/php/conf.d/

WORKDIR /var/www/html

ENV APP_ENV=local \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr

COPY --from=dependencies --chown=www-data:www-data /var/www/html /var/www/html

RUN mkdir -p \
        bootstrap/cache \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs \
    && chown -R www-data:www-data bootstrap/cache storage

USER www-data

EXPOSE 8000

CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
