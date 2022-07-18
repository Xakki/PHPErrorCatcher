FROM php:8.1-cli

WORKDIR /app

RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

RUN apt-get update && \
    apt-get install git libcurl4-openssl-dev libonig-dev zlib1g-dev libzip-dev -y
RUN docker-php-ext-install curl mbstring zip pdo

RUN docker-php-source delete && apt-get autoremove --purge -y && apt-get autoclean -y && apt-get clean -y

STOPSIGNAL SIGKILL

CMD tail -f /var/log/*.log -n 5