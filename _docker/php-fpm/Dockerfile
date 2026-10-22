FROM composer:lts as composer

FROM php:8.3-fpm-alpine AS prod
RUN echo 'prod stage'
WORKDIR "/application"
COPY --from=composer /usr/bin/composer /usr/bin/composer

RUN ln -sf /usr/bin/php8.3 /usr/bin/php
RUN ln -sf /usr/sbin/php-fpm8.3 /usr/sbin/php-fpm
RUN apk add icu-dev bash
RUN docker-php-ext-configure intl && docker-php-ext-install intl
RUN docker-php-ext-install pdo pdo_mysql
RUN docker-php-ext-install opcache
RUN apk add --update --no-cache --virtual .build-dependencies $PHPIZE_DEPS \
        && pecl install apcu \
        && docker-php-ext-enable apcu \
        && pecl clear-cache \
        && apk del .build-dependencies


RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY ./_docker/php-fpm/opcache.ini /$PHP_INI_DIR
COPY ./_docker/php-fpm/entrypoint.sh /etc/entrypoint.sh
COPY . /application
RUN rm -rf _docker
RUN rm -rf /application/var/ && mkdir -p /application/var/cache/prod /application/var/log && chown -R www-data: /application/var/
RUN chmod +x /etc/entrypoint.sh
ENV  APP_ENV=prod
RUN composer install --no-dev -o
RUN rm -rf /usr/bin/composer
EXPOSE 80
RUN chown -R www-data: /application
USER www-data
ENTRYPOINT ["/etc/entrypoint.sh"]

FROM prod AS test
# prod (the base of this stage) already switched to www-data - back to root for the
# file ops below (composer binary/vendor come from root-owned COPY --from sources),
# then drop to www-data again at the end, same as prod.
USER root
WORKDIR "/application"
COPY --from=composer /usr/bin/composer /usr/bin/composer
COPY --from=prod /application/ /application/
COPY --from=prod /etc/entrypoint.sh /etc/entrypoint.sh
RUN rm -rf vendor
ENV  APP_ENV=test
RUN composer install -o
RUN rm -rf /usr/bin/composer
EXPOSE 80
USER www-data
ENTRYPOINT ["/etc/entrypoint.sh"]

FROM phpdockerio/php:8.3-fpm AS dev
WORKDIR /application

ARG USER_ID=1000
ENV DEBIAN_FRONTEND=noninteractive

RUN apt-get update \
 && apt-get install -y --no-install-recommends \
      git \
      curl \
      ca-certificates \
      php8.3-bz2 \
      php8.3-mysql \
      php8.3-intl \
      php8.3-xdebug \
      php8.3-gd \
 && rm -rf /var/lib/apt/lists/*

# Node is only needed to build the Tailwind/DaisyUI stylesheet - AssetMapper
# still handles Stimulus/Turbo without it.
RUN curl -fsSL https://deb.nodesource.com/setup_lts.x | bash - \
 && apt-get install -y --no-install-recommends nodejs \
 && rm -rf /var/lib/apt/lists/*

RUN update-alternatives --install /usr/bin/php php /usr/bin/php8.3 83 \
 && update-alternatives --set php /usr/bin/php8.3 \
 && update-alternatives --install /usr/sbin/php-fpm php-fpm /usr/sbin/php-fpm8.3 83 \
 && update-alternatives --set php-fpm /usr/sbin/php-fpm8.3

RUN usermod -u ${USER_ID} www-data \
    && touch /run/php-fpm.pid \
    && chown www-data:www-data /run/php-fpm.pid
USER www-data
