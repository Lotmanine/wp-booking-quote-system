FROM composer:2.11.1 AS vendor
WORKDIR /build
COPY dispatch/composer.json dispatch/composer.lock ./
COPY dispatch/ ./
RUN composer install --no-dev --no-interaction --prefer-dist --ignore-platform-reqs --optimize-autoloader --no-scripts

FROM php:8.5.12-apache

RUN set -eux; \
	docker-php-ext-install pdo_mysql; \
	a2enmod rewrite; \
	sed -ri 's!/var/www/html!/var/www/html/public!g' /etc/apache2/sites-available/000-default.conf; \
	sed -ri 's!Listen 80!Listen 8080!' /etc/apache2/ports.conf; \
	sed -ri 's!:80>!:8080>!' /etc/apache2/sites-available/000-default.conf

COPY docker/dispatch/php.ini /usr/local/etc/php/conf.d/zz-dispatch.ini
COPY docker/dispatch/apache.conf /etc/apache2/conf-enabled/zz-dispatch.conf
COPY --from=vendor /build /var/www/html

RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache
