# syntax=docker/dockerfile:1
FROM php:8.3-apache

RUN docker-php-ext-install pdo pdo_mysql \
	&& a2enmod rewrite headers

WORKDIR /app
COPY . /app

RUN cp /app/docker/apache-vhost.conf /etc/apache2/sites-available/000-default.conf \
	&& chmod +x /app/docker/worker-entrypoint.sh \
	&& chown -R www-data:www-data /app

EXPOSE 80
CMD ["apache2-foreground"]