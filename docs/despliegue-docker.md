# FIEA con Docker y Cloudflare Tunnel

`compose.production.yaml` separa aplicación, worker, scheduler y MySQL 8.4. La base no publica puertos. El HTTP de FIEA se publica solo en loopback y en la dirección del puente Docker utilizada por el conector de Cloudflare. El proyecto Compose se llama `fiea`.

Antes de usar las direcciones predeterminadas, comprueba que `172.19.0.0/24`, `172.17.0.1` y el puerto `18082` correspondan al servidor y estén disponibles. Se pueden ajustar mediante `FIEA_DOCKER_SUBNET`, `FIEA_TUNNEL_BIND_IP` y `FIEA_HTTP_PORT`.

La configuración privada reside fuera del repositorio, por defecto en `../fiea-shared`: `app.env`, `database.env` y `storage/`. Conserva las claves de aplicación y respaldos entre actualizaciones. Cada entorno usa contraseñas independientes para usuario y administrador de MySQL.

MySQL exige TLS. Antes del arranque, prepara una CA privada y un certificado de servidor con `subjectAltName=DNS:db`. Guarda `ca.pem`, `server-cert.pem` y `server-key.pem` en `mysql-tls/`; el usuario `mysql` del contenedor debe poder leerlos. Conserva la clave de la CA fuera de ese montaje y fuera de los contenedores de aplicación. Copia únicamente el certificado público de la CA a `mysql-ca.pem` y configura `MYSQL_ATTR_SSL_CA=/run/fiea/mysql-ca.pem` en `app.env`.

El cliente MariaDB instalado por la imagen lee `mysql-client.cnf`, montado en `/etc/mysql/conf.d/fiea.cnf`. Su contenido debe ser:

```ini
[client]
ssl-ca=/run/fiea/mysql-ca.pem
ssl-verify-server-cert=1
```

Así PDO y los respaldos verifican la cadena y el nombre del servidor. No desactives la validación TLS para aceptar certificados autofirmados. Incluye la CA, certificados, claves y configuración privada en la recuperación operativa y programa su renovación antes del vencimiento.

La imagen se construye desde un commit publicado:

```bash
docker build --pull -t fiea:COMMIT .
export FIEA_IMAGE=fiea:COMMIT
docker compose -f compose.production.yaml up -d db --wait
```

Solo durante la instalación inicial de una base vacía se ejecutan las migraciones y los seeders de permisos, catálogos y administrador. Los seeders no se ejecutan como actualización rutinaria: el seeder de superadministrador rota sus credenciales. La clave de inicialización se retira de `app.env` después de preparar la cuenta.

```bash
docker compose -f compose.production.yaml run --rm --no-deps app php artisan migrate --force
docker compose -f compose.production.yaml run --rm --no-deps app php artisan db:seed --force
docker compose -f compose.production.yaml up -d --wait
docker compose -f compose.production.yaml exec -T app php artisan fiea:production-check
```

En las actualizaciones se conservan los archivos privados y volúmenes, se respalda la base y se ejecutan únicamente las migraciones pendientes. Mantén disponible la etiqueta anterior para volver a la imagen previa si falla la verificación; no borres volúmenes para revertir código.

La ruta pública de Cloudflare debe apuntar al HTTP privado de FIEA y conservar el hostname solicitado. `APP_URL`, `TRUSTED_HOST_PATTERNS`, proxies confiables y cookies seguras deben corresponder al dominio. No se modifica el conector ni las rutas de otras aplicaciones.

Configura el proveedor de correo antes de habilitar entregas automáticas. Gmail SMTP se documenta en [correo-gmail.md](correo-gmail.md); Resend requiere su credencial y una ventana de idempotencia confirmada. SMTP bloquea la repetición automática de intentos inciertos porque el proveedor no ofrece esa deduplicación. El almacenamiento local es persistente en este despliegue; Cloudflare R2 requiere configuración explícita y no sustituye silenciosamente un disco mal configurado.
