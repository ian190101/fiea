# Despliegue en Hostinger

Esta guia asume Laravel 12 + Inertia React con MySQL y archivos en Cloudflare R2.

## Requisitos

- PHP 8.2 o superior.
- Extensiones PHP comunes de Laravel: `ctype`, `curl`, `dom`, `fileinfo`, `filter`, `hash`, `mbstring`, `openssl`, `pdo`, `pdo_mysql`, `session`, `tokenizer`, `xml`.
- MySQL creado en Hostinger.
- Composer disponible localmente o en el servidor.
- Node local para compilar assets antes de subir, si el hosting no permite build con Node.

## Preparar build local

Antes de subir archivos:

```bash
composer install --no-dev --optimize-autoloader
npm install
npm run build
php artisan config:clear
php artisan route:clear
php artisan view:clear
php artisan test
```

Sube el proyecto con `vendor/` y `public/build/` si no ejecutarás Composer/Node en el servidor.

## Estructura recomendada

En Hostinger, apunta el dominio al directorio `public/` del proyecto. Si el panel no permite apuntar directamente a `public/`, coloca el contenido de `public/` en `public_html/` y ajusta los paths de `index.php` hacia el proyecto real.

Ejemplo conceptual:

```text
/home/usuario/fiea-app
/home/usuario/public_html -> apunta o contiene public/
```

## Variables `.env`

Configura como minimo:

```dotenv
APP_NAME="FIEA"
APP_ENV=production
APP_KEY=base64:...
APP_DEBUG=false
APP_URL=https://tu-dominio.com
APP_LOCALE=es

DB_CONNECTION=mysql
DB_HOST=...
DB_PORT=3306
DB_DATABASE=...
DB_USERNAME=...
DB_PASSWORD=...

FILESYSTEM_DISK=r2
CLOUDFLARE_R2_ACCESS_KEY_ID=...
CLOUDFLARE_R2_SECRET_ACCESS_KEY=...
CLOUDFLARE_R2_BUCKET=...
CLOUDFLARE_R2_ENDPOINT=...
CLOUDFLARE_R2_PUBLIC_URL=...

MAIL_MAILER=smtp
MAIL_HOST=...
MAIL_PORT=587
MAIL_USERNAME=...
MAIL_PASSWORD=...
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=...
MAIL_FROM_NAME="FIEA"

SESSION_DRIVER=database
SESSION_ENCRYPT=true
SESSION_SECURE_COOKIE=true
SESSION_HTTP_ONLY=true
SESSION_SAME_SITE=lax
```

## Primer despliegue

Ejecuta:

```bash
php artisan migrate --force
php artisan db:seed --force
php artisan storage:link
php artisan optimize
php artisan fiea:production-check
```

El seeder crea permisos, roles base, usuario `admin`, catalogos iniciales y plantillas de correo. Cambia la contrasena del usuario `admin` inmediatamente despues de entrar.

## Scheduler

Configura un cron job cada minuto:

```bash
* * * * * cd /home/usuario/fiea-app && php artisan schedule:run >> /dev/null 2>&1
```

El scheduler ejecuta la automatizacion de correos de invoices cada quince minutos.
Tambien genera un backup SQL diario a las 02:00 con `fiea:backup-database`.

## Colas

Para bajo volumen puede usarse `QUEUE_CONNECTION=sync`, pero en produccion con mas usuarios conviene `database` o Redis si el hosting lo permite.
El envio manual de correos de invoices ya esta preparado como Job, por lo que con una cola real la request solo encola el trabajo y el worker hace el envio con el PDF adjunto.

Con cola database:

```bash
php artisan queue:table
php artisan migrate --force
php artisan queue:work --tries=3 --timeout=90
```

En Hostinger, si no hay procesos permanentes, deja `sync` hasta migrar a un VPS o servicio con supervisor.
Si Hostinger permite cron pero no workers permanentes, una alternativa intermedia es ejecutar el worker por lotes:

```bash
* * * * * cd /home/usuario/fiea-app && php artisan queue:work database --queue=emails,default --stop-when-empty --tries=1 --timeout=90 >> /dev/null 2>&1
```

## Verificacion posterior

1. Entra como superadministrador.
2. Revisa `/operaciones`.
3. Sube un logo en configuracion.
4. Genera un draft budget PDF.
5. Genera un invoice PDF.
6. Prepara y envia un correo de invoice con SMTP real.
7. Revisa auditoria y alertas.
8. Genera un backup desde `/backups` y descarga el archivo SQL.

## Backups y restauracion

Backups manuales:

```bash
php artisan fiea:backup-database
```

Los backups se guardan en el almacenamiento activo. En produccion debe ser Cloudflare R2; si R2 no esta listo, el sistema usa almacenamiento local como fallback.

Restauracion manual recomendada:

```bash
mysql -u USUARIO -p BASE_DE_DATOS < fiea-database-YYYYMMDD-HHMMSS.sql
php artisan optimize:clear
php artisan migrate --force
php artisan db:seed --force
php artisan fiea:production-check
```

No restaures un backup sobre una base activa sin crear antes una copia nueva desde el panel de Hostinger. Una restauracion sobrescribe tablas y puede eliminar datos creados despues del backup.

## Comandos de mantenimiento

```bash
php artisan fiea:production-check --fail-on-warning
php artisan fiea:backup-database
php artisan fiea:email-automation
php artisan optimize:clear
php artisan optimize
```

Usa `optimize:clear` antes de cambios grandes de `.env` o rutas, y `optimize` despues de confirmar que todo funciona.
