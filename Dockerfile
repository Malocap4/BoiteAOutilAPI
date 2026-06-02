FROM php:8.2-apache

# Installation des dépendances système pour PhpSpreadsheet
RUN apt-get update && apt-get install -y \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    zip \
    libzip-dev \
    unzip \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) gd zip

# Activation du module Apache Rewrite
RUN a2enmod rewrite

# On se place dans le dossier web
WORKDIR /var/www/html

# On copie d'abord tes fichiers PHP (index.php, traitement.php)
COPY . /var/www/html/

# Installation de Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# ICI : On force l'installation de PhpSpreadsheet directement en ligne de commande
# (Plus besoin d'avoir un fichier composer.json sur ton GitHub !)
RUN composer require phpoffice/phpspreadsheet --no-interaction --optimize-autoloader

# Donne les bons droits d'accès
RUN chown -R www-data:www-data /var/www/html

EXPOSE 80
