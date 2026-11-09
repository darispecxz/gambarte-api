# CGR API

API REST desarrollada con Laravel 8 para los módulos de gestión, reportes y operaciones de CGR. Integra módulos Laravel actuales y servicios PHP heredados en `src/legacy/`.

## Requisitos

- PHP 8.0 o superior compatible con Laravel 8 (producción documentada con PHP 8.3).
- Composer.
- MySQL/MariaDB y las extensiones PHP requeridas por Laravel, incluyendo `pdo_mysql`, `mbstring`, `openssl`, `tokenizer` y `json`.

## Instalación local

```bash
composer install
Copy-Item .env.example .env
php artisan key:generate
```

Configura en `.env` las conexiones de base de datos y los servicios que necesite tu entorno. Las credenciales de módulos heredados se configuran en archivos locales `config.php`; parte de ellos están excluidos de Git. Usa los `config.sample.php` como referencia y no guardes credenciales en el repositorio.

Prepara la aplicación y arranca el servidor de desarrollo:

```bash
php artisan migrate
php artisan serve
```

La migración puede omitirse si no deseas modificar una base de datos local. Revisa cada archivo SQL antes de ejecutarlo en un entorno con datos existentes. La API queda disponible en `http://127.0.0.1:8000/api`.

## Rutas y módulos

Las rutas API se definen en `routes/api.php` y en los módulos de `app/Modules/*/Routes/`. El endpoint `GET /api/health` permite comprobar el estado básico del servicio. Los módulos incluyen auditoría, bancos, cajero, contabilidad, discrepancias, facturación, operaciones, POS, riesgos, RRHH, UIF y utilidad cambiaria.

La configuración de la aplicación está en `config/`; las migraciones y scripts SQL están en `database/`. Los comandos programados se configuran en `app/Console/Kernel.php` y sus comandos están en `app/Console/Commands/`.

## Variables de entorno

`.env.example` contiene valores de muestra sin credenciales. Ajusta al menos:

- `APP_KEY` (generada con `php artisan key:generate`).
- `DB_*` y las variables `DB_CGR_*`, `DB_CONTA_*` y `DB_CHILE_*` necesarias.
- `CORS_ALLOWED_ORIGINS` para los orígenes autorizados.
- Secretos de JWT, Binance Pay u otros servicios solo cuando esos módulos estén habilitados.

No publiques `.env`, archivos `config.php` con credenciales, claves privadas ni datos exportados de clientes.

## Despliegue

Consulta [DEPLOY_HOSTINGER.md](DEPLOY_HOSTINGER.md) para los pasos específicos de Hostinger, configuración del document root, permisos y tareas programadas.

## Dependencias

Las dependencias PHP están declaradas en `composer.json` y fijadas en `composer.lock`. `vendor/` se genera con Composer y no debe subirse al repositorio.
