FROM composer:2 AS composer
FROM php:8.5-apache
COPY --from=composer /usr/bin/composer /usr/local/bin/composer
COPY docker/setup-dev-container.sh /tmp/setup.sh
RUN bash /tmp/setup.sh
WORKDIR /var/www/html
