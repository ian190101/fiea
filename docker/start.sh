#!/usr/bin/env bash
set -euo pipefail

export PORT="${PORT:-8080}"

sed -i "s/Listen 80/Listen ${PORT}/g" /etc/apache2/ports.conf
sed -i "s/\${PORT}/${PORT}/g" /etc/apache2/sites-available/000-default.conf

if [ -n "${MYSQL_ATTR_SSL_CA_CONTENT:-}" ]; then
    printf '%s' "${MYSQL_ATTR_SSL_CA_CONTENT}" > /tmp/tidb-ca.pem
    export MYSQL_ATTR_SSL_CA=/tmp/tidb-ca.pem
fi

if [ -z "${APP_KEY:-}" ]; then
    echo "APP_KEY no esta configurado. Define APP_KEY en Render antes de produccion." >&2
    exit 1
fi

php artisan storage:link --force || true

# Las migraciones se ejecutan en arranque para que Render cree/actualice el esquema automaticamente.
php artisan migrate --force

php artisan config:cache
php artisan route:cache
php artisan view:cache

(
    while true; do
        php artisan queue:work "${QUEUE_CONNECTION:-database}" --queue=emails,default --sleep=3 --tries=12 --timeout=120 --backoff=60,300,900,1800,3600
        echo "El worker de colas se detuvo; reiniciando en 5 segundos." >&2
        sleep 5
    done
) &

exec apache2-foreground
