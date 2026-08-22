# syntax=docker/dockerfile:1
#
# Production image for the Nusantara Construction ERP (Laravel 12, API-only).
#
# Stage 1 (vendor) installs Composer dependencies; stage 2 is the php-fpm
# runtime. The same image is used by the app (php-fpm), queue worker and
# scheduler services in docker-compose.prod.yml — only the CMD differs.
#
# NOTE ON composer.lock: the repository does not yet contain a composer.lock
# (composer has never been run against it). Until one is committed, the
# vendor stage resolves dependencies from composer.json alone, which is NOT
# reproducible between builds. The first CI run must execute
# `composer install` and commit the generated composer.lock; the wildcard
# COPY below picks it up automatically once it exists — no Dockerfile change
# is needed.

########################################################################
# Stage 1 — Composer dependencies
########################################################################
FROM composer:2 AS vendor

WORKDIR /app

# Dependency manifest first, so the (expensive) install layer is cached
# until composer.json / composer.lock change. `composer.lock*` matches
# nothing today and the lock file once it is committed.
COPY composer.json composer.lock* ./

# --no-scripts: post-autoload-dump runs `artisan package:discover`, which
#   needs the full app + prod PHP extensions; the entrypoint compensates by
#   running package:discover at container start.
# --ignore-platform-req=ext-*: the composer image lacks gd/intl/etc.; the
#   runtime stage below provides them. PHP version constraints still apply.
RUN composer install \
        --no-dev \
        --prefer-dist \
        --no-interaction \
        --no-progress \
        --no-scripts \
        --no-autoloader \
        --ignore-platform-req=ext-*

# Full application source (filtered by .dockerignore), then the optimized
# production autoloader.
COPY . .

RUN composer dump-autoload \
        --no-dev \
        --optimize \
        --no-scripts

########################################################################
# Stage 2 — php-fpm runtime
########################################################################
FROM php:8.3-fpm-alpine

# PHP extensions:
#   pdo_mysql          — MySQL 8
#   bcmath             — money / tax arithmetic
#   gd, zip            — maatwebsite/excel (phpspreadsheet) + dompdf
#   intl               — locale-aware formatting (id_ID)
#   opcache            — production bytecode cache
#   pcntl              — graceful signal handling for queue:work
#   redis (phpredis)   — cache/session/queue stores (config/database.php
#                        defaults REDIS_CLIENT=phpredis; predis is not a
#                        composer dependency, so the extension is required)
COPY --from=ghcr.io/mlocati/php-extension-installer:latest /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions pdo_mysql bcmath gd zip intl opcache pcntl redis \
    && rm /usr/local/bin/install-php-extensions \
    && apk add --no-cache tzdata

# Asia/Jakarta by default; override with TZ in the environment.
# deploy/docker/php.ini reads date.timezone from ${TZ}.
ENV TZ=Asia/Jakarta

# Production php.ini base + our overrides; php-fpm pool config; entrypoint.
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY deploy/docker/php.ini /usr/local/etc/php/conf.d/zz-erp.ini
COPY deploy/docker/www.conf /usr/local/etc/php-fpm.d/zz-erp.conf
COPY deploy/docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod 0755 /usr/local/bin/entrypoint.sh

# Non-root runtime user.
RUN addgroup -g 1000 erp \
    && adduser -u 1000 -G erp -s /bin/sh -D erp

WORKDIR /var/www/html

# Application (source + vendor) from the composer stage.
COPY --from=vendor --chown=erp:erp /app /var/www/html

# Writable runtime directories (contents were stripped by .dockerignore).
RUN mkdir -p \
        storage/app/private \
        storage/app/public \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs \
        bootstrap/cache \
    && chown -R erp:erp storage bootstrap/cache \
    && chmod -R ug+rwX storage bootstrap/cache

USER erp

EXPOSE 9000

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["php-fpm"]
