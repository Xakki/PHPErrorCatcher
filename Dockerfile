FROM php:5.6-cli-alpine

WORKDIR /app

RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer --1

RUN apk update && apk add git libzip-dev curl-dev -y
RUN docker-php-ext-install curl mbstring zip pdo
RUN docker-php-source delete && rm -rf /var/cache/apk/*

STOPSIGNAL SIGKILL

##CMD tail -f /var/log/*.log -n 5