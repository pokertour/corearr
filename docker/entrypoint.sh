#!/bin/sh

# Correction des droits pour SQLite et les uploads (nécessaire pour les volumes montés)
mkdir -p storage/framework/views storage/framework/cache/data storage/framework/sessions storage/logs
chown -R www-data:www-data /var/www/html/storage /var/www/html/database
chmod -R 775 /var/www/html/storage /var/www/html/database
chmod -R 775 /var/www/html/bootstrap/cache 2>/dev/null || true

# Création de la DB si absente
if [ ! -f /var/www/html/database/database.sqlite ]; then
    touch /var/www/html/database/database.sqlite
    chown www-data:www-data /var/www/html/database/database.sqlite
fi

# Routine Laravel (migrations toujours nécessaires)
php artisan migrate --force
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