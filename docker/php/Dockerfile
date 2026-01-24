FROM php:8.4-cli AS php_base

ARG timezone=Asia/Bangkok

ENV TIMEZONE=${timezone}
RUN ln -sf /usr/share/zoneinfo/${TIMEZONE} /etc/localtime \
    && echo "${TIMEZONE}" > /etc/timezone

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        curl wget unzip git \
        postgresql-client libpq-dev libsodium-dev \
        libmemcached-dev zlib1g-dev \
    && docker-php-ext-install pdo pdo_pgsql pgsql pcntl sockets \
    && cd /tmp \
    && git clone --depth 1 --branch v3.2.0 https://github.com/php-memcached-dev/php-memcached.git \
    && cd php-memcached \
    && phpize && ./configure && make && make install \
    && docker-php-ext-enable memcached \
    && rm -rf /tmp/php-memcached \
    && apt-get clean && rm -rf /var/lib/apt/lists/* /tmp/*

RUN curl -sSL https://github.com/swoole/swoole-src/archive/refs/tags/v6.1.2.tar.gz | tar xz -C /tmp \
    && cd /tmp/swoole-src-6.1.2 \
    && phpize \
    && ./configure --enable-sockets --enable-openssl --enable-http2 --enable-pgsql \
    && make && make install \
    && docker-php-ext-enable swoole \
    && rm -rf /tmp/swoole-src-6.1.2

ENV COMPOSER_ALLOW_SUPERUSER=1
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

ENV PATH=/var/www/app/vendor/bin:${PATH}
WORKDIR /var/www/app

COPY docker/php/conf.d/php.ini /usr/local/etc/php/conf.d/999-php.ini

EXPOSE 9501

FROM php_base AS php_dev
ENV APP_ENV=dev
COPY docker/php/conf.d/php.dev.ini /usr/local/etc/php/conf.d/998-php-dev.ini

FROM php_base AS php_prod
ENV APP_ENV=prod

FROM php_base AS composer
CMD ["composer", "install"]
