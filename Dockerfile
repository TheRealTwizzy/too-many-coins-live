# syntax=docker/dockerfile:1
FROM php:8.3-fpm-alpine

RUN docker-php-ext-install pdo pdo_mysql

WORKDIR /app
COPY . /app

RUN chown -R www-data:www-data /app

EXPOSE 9000
CMD ["php-fpm"]