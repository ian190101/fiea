# Despliegue en Render con Docker

Este proyecto usa `Dockerfile` y `render.yaml` para crear un web service simple en Render.

## Variables obligatorias en Render

Configura estos valores en el panel de Render. No los subas al repositorio.

```env
APP_KEY=base64:...
APP_URL=https://<tu-servicio>.onrender.com

DB_CONNECTION=mysql
DB_HOST=gateway01.us-east-1.prod.aws.tidbcloud.com
DB_PORT=4000
DB_DATABASE=fiea_sist
DB_USERNAME=<usuario_tidb>
DB_PASSWORD=<password_tidb>
MYSQL_ATTR_SSL_CA=/etc/ssl/certs/ca-certificates.crt

REDIS_CLIENT=phpredis
REDIS_URL=<redis_url_de_render>
CACHE_STORE=redis
SESSION_DRIVER=redis
QUEUE_CONNECTION=database
DB_QUEUE=default
```

## Correo real

Para que los correos lleguen a una bandeja real, configura SMTP:

```env
MAIL_MAILER=smtp
MAIL_HOST=smtp.gmail.com
MAIL_PORT=465
MAIL_USERNAME=<smtp_user>
MAIL_PASSWORD=<smtp_password>
MAIL_FROM_ADDRESS=<correo_emisor>
MAIL_FROM_NAME=FIEA
MAIL_SCHEME=smtps
MAIL_TIMEOUT=30
```

En Laravel 12 `MAIL_SCHEME` no acepta `tls`. Para Gmail usa `smtps` con puerto `465`.
Si usas puerto `587`, el scheme debe ser `smtp`, pero en Render puede quedar bloqueado o lento por STARTTLS.

## Archivos

El `render.yaml` deja `FILESYSTEM_DISK=local` para un primer despliegue simple. Para producción real con PDFs, recibos y logos persistentes, configura Cloudflare R2 y cambia:

```env
FILESYSTEM_DISK=r2
CLOUDFLARE_R2_ACCESS_KEY_ID=...
CLOUDFLARE_R2_SECRET_ACCESS_KEY=...
CLOUDFLARE_R2_BUCKET=...
CLOUDFLARE_R2_ENDPOINT=...
CLOUDFLARE_R2_PUBLIC_URL=...
```

## Migraciones

El contenedor ejecuta `php artisan migrate --force` al arrancar. Esto facilita el primer despliegue, pero en sistemas con mucho trafico conviene mover migraciones a un release step separado.
