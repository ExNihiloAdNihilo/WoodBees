FROM php:7.4-fpm-alpine as base

# php:7.4-fpm-alpine is pinned to Alpine 3.16 (no more rebuilds, PHP 7.4 is EOL),
# so its baked-in CA bundle predates a cert-chain rotation on Alpine's CDN and
# apk's HTTPS fetches fail with "unable to get local issuer certificate". apk
# packages are signed independent of TLS, so fetching over plain HTTP is safe.
RUN sed -i 's/https/http/' /etc/apk/repositories

RUN apk --no-cache update \
    && apk --no-cache upgrade \
    && apk add --no-cache $PHPIZE_DEPS \
        freetype \
        freetype-dev \
        gettext-dev \
        icu-dev \
        libjpeg-turbo \
        libjpeg-turbo-dev \
        libpng \
        libpng-dev

RUN docker-php-ext-install -j$(getconf _NPROCESSORS_ONLN) exif \
        gd \
        gettext \
        intl \
        mysqli

FROM base as local

RUN pecl install xdebug-2.9.8 \
    && docker-php-ext-enable xdebug \
    && echo "xdebug.remote_enable=on" >> /usr/local/etc/php/conf.d/docker-php-ext-xdebug-config.ini \
    && echo "xdebug.remote_host=host.docker.internal" >> /usr/local/etc/php/conf.d/docker-php-ext-xdebug-config.ini \
    && echo "xdebug.remote_port=9000" >> /usr/local/etc/php/conf.d/docker-php-ext-xdebug-config.ini