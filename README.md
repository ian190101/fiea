# FIEA Invoices & Budget

Sistema Laravel 12 + Inertia React para gestionar proyectos, capitulos, equipos, viajes, draft budgets, invoices IC/MAT, gastos reales, recibos, contabilidad, correos automaticos, reportes, auditoria y configuracion institucional.

## Stack

- Backend: Laravel 12, PHP 8.2+, MySQL.
- Frontend: Inertia, React, Tailwind CSS.
- PDF: DomPDF.
- Archivos: disco local en desarrollo y Cloudflare R2 en produccion.
- Auth: inicio de sesion por username.

## Instalacion local

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
npm run build
```

Para desarrollo:

```bash
php artisan serve
npm run dev
```

## Comandos operativos

```bash
php artisan fiea:production-check
php artisan fiea:production-check --json
php artisan fiea:backup-database
php artisan fiea:email-automation
```

El comando `fiea:production-check` valida base de datos, almacenamiento, cache, colas, scheduler y configuracion critica.
El comando `fiea:backup-database` genera un archivo SQL y lo guarda en el almacenamiento activo.

## Scheduler

En produccion debe ejecutarse cada minuto:

```bash
php artisan schedule:run
```

Ese scheduler dispara la automatizacion de correos de invoices cada quince minutos.
Tambien genera un backup de base de datos todos los dias a las 02:00.

## Despliegue

La guia especifica para Hostinger esta en [docs/deployment-hostinger.md](docs/deployment-hostinger.md).
