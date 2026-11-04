#!/usr/bin/env bash
set -euo pipefail

# Esta prueba se ejecuta en un contenedor efímero sin red ni base de datos.
mkdir /tmp/fiea-bin
printf '#!/bin/sh\nexit 0\n' > /tmp/fiea-bin/php
printf '#!/bin/sh\napache2ctl -t\n' > /tmp/fiea-bin/apache2-foreground
chmod +x /tmp/fiea-bin/*
export PATH="/tmp/fiea-bin:$PATH" APP_KEY=prueba

for candidate in 8080 8080 18082 18082; do
    PORT="$candidate" /usr/local/bin/start
    test "$(cat /etc/apache2/ports.conf)" = "Listen $candidate"
    grep -q "^<VirtualHost \\*:$candidate>" /etc/apache2/sites-available/000-default.conf
done

for invalid in 0 65536 abc; do
    if PORT="$invalid" /usr/local/bin/start; then
        echo "Se aceptó un puerto inválido: $invalid" >&2
        exit 1
    fi
done
test "$(cat /etc/apache2/ports.conf)" = 'Listen 18082'
printf 'Reinicios, cambio de puerto y rechazo de puertos inválidos: correctos.\n'
