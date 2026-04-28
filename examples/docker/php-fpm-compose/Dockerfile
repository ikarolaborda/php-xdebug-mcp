FROM php:8.3-fpm

RUN set -eux \
 && apt-get update \
 && apt-get install -y --no-install-recommends $PHPIZE_DEPS \
 && pecl install xdebug \
 && docker-php-ext-enable xdebug \
 && apt-get purge -y --auto-remove $PHPIZE_DEPS \
 && rm -rf /var/lib/apt/lists/*
