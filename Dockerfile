FROM php:8.2-apache

# 1. Installation des dépendances système nécessaires pour cURL, Zip et Gd (requis par PhpSpreadsheet)
RUN apt-get update && apt-get install -y \
    libzip-dev \
    zip \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    && rm -rf /var/lib/apt/lists/*

# 2. Configuration et installation des extensions PHP requises
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) gd zip pdo_mysql

# 3. Activation du module de réécriture d'Apache (Utile pour la gestion des routes/fichiers)
RUN a2enmod rewrite

# 4. Modification du port par défaut d'Apache pour utiliser celui fourni par Render
RUN sed -i 's/Listen 80/Listen ${PORT}/g' /etc/apache2/ports.conf \
    && sed -i 's/<VirtualHost \*:80>/<VirtualHost \*:${PORT}>/g' /etc/apache2/sites-available/000-default.conf

# 5. Configuration recommandée pour le fichier php.ini (Augmentation de la mémoire et temps d'exécution)
RUN echo "memory_limit = 512M" > /usr/local/etc/php/conf.d/custom.ini \
    && echo "upload_max_filesize = 20M" >> /usr/local/etc/php/conf.d/custom.ini \
    && echo "post_max_size = 20M" >> /usr/local/etc/php/conf.d/custom.ini \
    && echo "max_execution_time = 300" >> /usr/local/etc/php/conf.d/custom.ini

# 6. Copie des fichiers de l'application dans le conteneur
COPY . /var/www/html/

# 7. Installation de Composer et des dépendances PHP (PhpSpreadsheet)
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer
RUN composer install --no-dev --optimize-autoloader

# 8. Attribution des permissions pour Apache et la création des dossiers d'uploads
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html

# Définition du répertoire de travail
WORKDIR /var/www/html

# Exposition du port dynamique
EXPOSE ${PORT}