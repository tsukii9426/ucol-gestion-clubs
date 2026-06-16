FROM php:8.2-apache

# Instalar extensiones PDO y mysqli
RUN docker-php-ext-install mysqli pdo pdo_mysql \
    && a2enmod rewrite

# Permisos correctos
RUN chown -R www-data:www-data /var/www/html

EXPOSE 80
