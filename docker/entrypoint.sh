#!/bin/sh

# Correction des droits pour SQLite et les uploads (nécessaire pour les volumes montés)
mkdir -p storage/framework/views storage/framework/cache/data storage/framework/sessions storage/logs
chown -R www-data:www-data /var/www/html/storage /var/www/html/database
chmod -R 775 /var/www/html/storage /var/www/html/database
chmod -R 775 /var/www/html/bootstrap/cache 2>/dev/null || true

# Force APP_VERSION from image build metadata (avoids stale runtime env override)
if [ -f /etc/corearr_version ]; then
    export APP_VERSION="$(tr -d '\r\n' < /etc/corearr_version)"
fi

# Création de la DB si absente
DB_PATH="/var/www/html/database/database.sqlite"

# Sécurité : Si c'est un dossier (erreur de montage Docker fréquent), on le supprime
if [ -d "$DB_PATH" ]; then
    echo "⚠️  $DB_PATH est un dossier (erreur Docker), correction..."
    rm -rf "$DB_PATH"
fi

if [ ! -f "$DB_PATH" ]; then
    touch "$DB_PATH"
    chown www-data:www-data "$DB_PATH"
fi

# Routine Laravel (migrations toujours nécessaires)
php artisan migrate --force
php artisan db:seed --force
php artisan storage:link --force

# Optimisations (seulement en production pour ne pas bloquer le dev)
if [ "$APP_ENV" = "production" ]; then
    echo "🏗️  Optimisation pour la production..."
    php artisan config:cache
    php artisan route:cache
    php artisan view:cache
fi

echo "🚀 CoreArr (Octane + FrankenPHP 8.5) est prêt !"

exec /usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf