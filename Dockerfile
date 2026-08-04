FROM php:5.6-cli-alpine

WORKDIR /app

RUN apk update && apk add git libzip-dev curl-dev -y
RUN docker-php-ext-install curl mbstring zip pdo
RUN docker-php-source delete && rm -rf /var/cache/apk/*
# 2.2 — последняя LTS-ветка Composer, поддерживающая PHP 5.x (Composer 1 отключён Packagist'ом 2025-09-01)
COPY --from=composer:2.2 /usr/bin/composer /usr/bin/composer

STOPSIGNAL SIGKILL

##CMD tail -f /var/log/*.log -n 5