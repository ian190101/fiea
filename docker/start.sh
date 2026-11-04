#!/usr/bin/env bash
set -euo pipefail

export PORT="${PORT:-8080}"

if ! [[ "$PORT" =~ ^[0-9]{1,5}$ ]] || ((10#$PORT < 1 || 10#$PORT > 65535)); then
    echo "PORT debe ser un número entre 1 y 65535." >&2
    exit 1
fi
export PORT="$((10#$PORT))"

# Se regenera la escucha para que reiniciar no convierta 8080 en 808080.
printf 'Listen %s\n' "$PORT" > /etc/apache2/ports.conf
sed -i -E "s@^<VirtualHost \\*:[^>]+>@<VirtualHost *:${PORT}>@" /etc/apache2/sites-available/000-default.conf

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
php artisan fiea:rbac-seed-check

php artisan config:cache
php artisan route:cache
php artisan view:cache

exec apache2-foreground
