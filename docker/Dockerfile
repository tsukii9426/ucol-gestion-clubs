FROM php:8.2-apache

# Instalar extensiones PDO y mysqli
RUN docker-php-ext-install mysqli pdo pdo_mysql \
    && a2enmod rewrite

# Config de Apache: DirectoryIndex apunta a login.php
COPY apache/000-default.conf /etc/apache2/sites-available/000-default.conf

EXPOSE 80
