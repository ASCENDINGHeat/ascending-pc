# syntax=docker/dockerfile:1.7

FROM php:8.4-fpm-alpine AS base

COPY --from=mlocati/php-extension-installer:2 /usr/bin/install-php-extensions /usr/local/bin/

RUN apk add --no-cache \
        fcgi \
        su-exec \
    && install-php-extensions \
        bcmath \
        exif \
        gd \
        intl \
        opcache \
        pcntl \
        pdo_pgsql \
        pgsql \
        zip

WORKDIR /var/www/html

# -----------------------------------------------------------------------------
# Stage 2: Install production Composer dependencies
# -----------------------------------------------------------------------------
FROM base AS deps

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY composer.json composer.lock ./

RUN composer install \
    --no-dev \
    --no-interaction \
    --no-progress \
    --no-scripts \
    --prefer-dist \
    --optimize-autoloader

# -----------------------------------------------------------------------------
# Stage 3: Production PHP-FPM runtime image
# -----------------------------------------------------------------------------
FROM base AS runtime

COPY docker/php.ini /usr/local/etc/php/conf.d/99-production.ini
COPY docker/zz-docker.conf /usr/local/etc/php-fpm.d/zz-docker.conf
COPY docker/entrypoint.sh /usr/local/bin/docker-entrypoint.sh

RUN chmod +x /usr/local/bin/docker-entrypoint.sh

COPY --chown=www-data:www-data --from=deps /var/www/html/vendor ./vendor
COPY --chown=www-data:www-data . .

RUN mkdir -p \
        bootstrap/cache \
        storage/app/public \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/testing \
        storage/framework/views \
        storage/logs \
    && chown -R www-data:www-data bootstrap/cache storage \
    && su-exec www-data php artisan package:discover --ansi

EXPOSE 9000

HEALTHCHECK --interval=15s --timeout=5s --start-period=25s --retries=5 \
    CMD SCRIPT_NAME=/fpm-ping SCRIPT_FILENAME=/fpm-ping REQUEST_METHOD=GET cgi-fcgi -bind -connect 127.0.0.1:9000 | grep -q pong

ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["php-fpm"]
