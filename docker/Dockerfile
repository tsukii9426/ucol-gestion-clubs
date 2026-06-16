FROM php:8.2-apache

# Instalar extensiones PDO y mysqli
RUN docker-php-ext-install mysqli pdo pdo_mysql \
    && a2enmod rewrite

# Copiar toda la aplicación dentro de la imagen
# (el contexto de build es la raíz del repo, ver docker-compose.yml)
COPY . /var/www/html

# Sobreescribir con la configuración de Docker
COPY docker/config.php              /var/www/html/config.php
COPY docker/apache/000-default.conf /etc/apache2/sites-available/000-default.conf
COPY docker/php/custom.ini          /usr/local/etc/php/conf.d/custom.ini

RUN chown -R www-data:www-data /var/www/html

EXPOSE 80
