# Despliegue en Render con Docker

Este proyecto usa `Dockerfile` y `render.yaml` para desplegar un web service y un worker separado.

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
SESSION_SECURE_COOKIE=true
SESSION_SAME_SITE=lax

QUEUE_CONNECTION=database
DB_QUEUE=default
FIEA_EMAIL_QUEUE_ENABLED=true
```

## Correo real

Render bloquea SMTP en la practica para este caso. Los correos deben enviarse por API HTTPS con Resend.
Configura el dominio en Resend y agrega SPF, DKIM y DMARC antes de usarlo en produccion.

```env
FIEA_MAIL_PROVIDER=resend
RESEND_API_KEY=<resend_api_key>
RESEND_TIMEOUT=30
MAIL_MAILER=log
MAIL_FROM_ADDRESS=<correo_emisor>
MAIL_FROM_NAME=FIEA
```

Los correos oficiales se preparan siempre en ingles y pasan por `EmailLog` como outbox.
El envio real lo procesa el worker de Render con la cola `emails`.

## Archivos

El disco local de Render no es persistente. Para produccion real con PDFs, recibos, logos y backups, usa Cloudflare R2:

```env
FILESYSTEM_DISK=r2
CLOUDFLARE_R2_ACCESS_KEY_ID=...
CLOUDFLARE_R2_SECRET_ACCESS_KEY=...
CLOUDFLARE_R2_BUCKET=...
CLOUDFLARE_R2_ENDPOINT=...
CLOUDFLARE_R2_PUBLIC_URL=...
```

## Migraciones y RBAC

El contenedor web ejecuta `php artisan migrate --force` al arrancar. Esto facilita el primer despliegue, pero en sistemas con mucho trafico conviene mover migraciones a un release step separado.

Despues de migrar se ejecuta `php artisan fiea:rbac-seed-check`. Si los permisos o el rol `superadmin` estan incompletos, el deploy falla cerrado.

## Operacion

- El servicio web no ejecuta workers dentro del mismo proceso.
- El worker `fiea-worker` procesa `emails,default`.
- Rota cualquier secreto pegado accidentalmente en chats o logs: TiDB, Redis, Gmail/Resend y R2.
- Ejecuta `php artisan fiea:production-check --fail-on-warning` antes de promover cambios importantes.
