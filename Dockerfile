# --- Étape 1 : Compilation des Assets (Node) ---
FROM node:22-alpine AS assets-builder
WORKDIR /app
COPY package*.json ./
RUN npm install
COPY . .
# Tailwind 4 build
RUN npm run build

# --- Étape 2 : L'application Octane + FrankenPHP 8.5 ---
FROM dunglas/frankenphp:1-php8.5-alpine

# Configuration globale pour Octane et Redis (Baked-in)
ENV OCTANE_SERVER=frankenphp \
    REDIS_HOST=redis \
    CACHE_STORE=redis \
    SESSION_DRIVER=redis \
    QUEUE_CONNECTION=redis \
    APP_ENV=production \
    APP_RUNTIME_CACHE=true

# Installation des dépendances système (Supervisor)
RUN apk add --no-cache supervisor git

# Installation des extensions via l'installeur pré-inclus
RUN install-php-extensions \
    gd \
    exif \
    pdo_mysql \
    pdo_pgsql \
    zip \
    intl \
    bcmath \
    opcache \
    mbstring \
    redis \
    pcntl \
    posix

# Optimisations PHP 8.5 (JIT)
RUN echo "upload_max_filesize=12M" > /usr/local/etc/php/conf.d/prod.ini && \
    echo "post_max_size=12M" >> /usr/local/etc/php/conf.d/prod.ini && \
    echo "memory_limit=512M" >> /usr/local/etc/php/conf.d/prod.ini && \
    echo "opcache.enable_cli=1" >> /usr/local/etc/php/conf.d/prod.ini && \
    echo "opcache.jit=tracing" >> /usr/local/etc/php/conf.d/prod.ini


# Config Supervisor
COPY docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf

# Script d'entrée (Entrypoint)
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

# Installation de Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Copie du code et des assets compilés
COPY . .
COPY --from=assets-builder /app/public/build ./public/build

# Installation propre des dépendances
RUN composer install --no-dev --optimize-autoloader

# On prépare les dossiers pour les volumes et Caddy
RUN mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views database /config/caddy /data/caddy \
    && chown -R www-data:www-data /var/www/html /config /data

# FrankenPHP utilise le port 80 par défaut
EXPOSE 80

ENTRYPOINT ["entrypoint.sh"]