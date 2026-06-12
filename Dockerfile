ARG PHP_VERSION=8.3
FROM php:${PHP_VERSION}-cli-alpine

WORKDIR /app

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

RUN apk --no-cache  update && apk --no-cache add oniguruma-dev libzip-dev curl-dev linux-headers postgresql-dev
RUN docker-php-ext-install curl mbstring zip pdo pdo_mysql pdo_pgsql sockets
RUN docker-php-source delete && rm -rf /var/cache/apk/*

STOPSIGNAL SIGKILL

##CMD tail -f /var/log/*.log -n 5
