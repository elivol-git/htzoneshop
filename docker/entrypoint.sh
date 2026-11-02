#!/bin/sh
set -e
cd /var/www/html

# Production: pull DB credentials / HF token / APP_KEY from AWS Secrets Manager
# (EC2 instance role) into .env. Locally, env comes from docker compose.
if [ -n "$AWS_SECRET_ID" ]; then
  echo "[entrypoint] loading secrets from $AWS_SECRET_ID"
  php docker/fetch-secrets.php > .env
  chown www-data:www-data .env && chmod 600 .env
fi

php artisan config:cache
php artisan route:cache
php artisan view:cache

# Wait for DB, then migrate (idempotent).
i=0
until php artisan db:show >/dev/null 2>&1; do
  i=$((i+1)); [ $i -ge 30 ] && { echo "[entrypoint] database unreachable"; exit 1; }
  echo "[entrypoint] waiting for database..."; sleep 2
done
php artisan migrate --force

# First boot: load catalog if the table is empty (embeddings via `catalog:embed`).
if [ "${AUTO_IMPORT_CATALOG:-true}" = "true" ]; then
  php artisan catalog:import --no-interaction || echo "[entrypoint] catalog import failed"
fi
chown -R www-data:www-data storage bootstrap/cache

exec "$@"
