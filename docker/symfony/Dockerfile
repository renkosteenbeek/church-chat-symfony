FROM php:8.4-fpm-alpine

RUN apk add --no-cache \
    git \
    unzip \
    libzip-dev \
    icu-dev \
    rabbitmq-c-dev \
    autoconf \
    g++ \
    make \
    linux-headers \
    tzdata \
    supervisor \
    bash

ENV TZ=Europe/Amsterdam
RUN ln -snf /usr/share/zoneinfo/$TZ /etc/localtime && echo $TZ > /etc/timezone

RUN docker-php-ext-install \
    pdo_mysql \
    zip \
    intl \
    opcache \
    bcmath \
    sockets

RUN pecl install amqp && docker-php-ext-enable amqp
RUN pecl install apcu && docker-php-ext-enable apcu
RUN pecl install xdebug && docker-php-ext-enable xdebug

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY docker/php/timezone.ini /usr/local/etc/php/conf.d/timezone.ini
COPY docker/php/xdebug.ini /usr/local/etc/php/conf.d/xdebug.ini

WORKDIR /app

COPY . .

RUN mkdir -p var/cache var/log storage var/tmp && \
    chown -R www-data:www-data var/ storage/ && \
    chmod -R 755 var/

ENV COMPOSER_ALLOW_SUPERUSER=1
ENV APP_ENV=${APP_ENV:-prod}
ENV APP_DEBUG=${APP_DEBUG:-0}
ENV XDEBUG_MODE=${XDEBUG_MODE:-off}

CMD ["php-fpm"]

EXPOSE 9000