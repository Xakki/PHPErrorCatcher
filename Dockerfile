FROM php:5.6-cli-alpine

WORKDIR /app

RUN apk update && apk add git libzip-dev curl-dev -y
RUN docker-php-ext-install curl mbstring zip pdo
RUN docker-php-source delete && rm -rf /var/cache/apk/*
COPY --from=composer:1 /usr/bin/composer /usr/bin/composer

STOPSIGNAL SIGKILL

##CMD tail -f /var/log/*.log -n 5