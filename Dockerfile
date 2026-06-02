FROM php:8.2-apache

# Installation des dépendances système requises pour PhpSpreadsheet
RUN apt-get update && apt-get install -y \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    zip \
    libzip-dev \
    unzip \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) gd zip

# Activation du module Apache Rewrite (souvent utile)
RUN a2enmod rewrite

# Copie des fichiers du projet dans le dossier du serveur web
COPY . /var/www/html/

# Installation de Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer
RUN cd /var/www/html && composer install --no-dev --optimize-autoloader

# Donne les bons droits d'accès aux fichiers
RUN chown -R www-data:www-data /var/www/html

EXPOSE 80