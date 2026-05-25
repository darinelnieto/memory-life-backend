# Memory Life Back-end

API de Memory Life construida con Laravel 13 + Sanctum.

## Requisitos

- PHP 8.3+
- Composer 2+
- Base de datos MySQL o SQLite

## Instalacion

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
```

## Variables de entorno clave

- `APP_URL`: URL del back-end.
- `FRONTEND_URL`: URL del front-end para enlaces de reset password.
- `DB_*`: conexion de base de datos.
- `MAIL_*`: proveedor de correo para envio de enlaces de recuperacion.

## Comandos utiles

```bash
php artisan serve
php artisan migrate
php artisan test
```

## Endpoints de autenticacion

- `POST /api/auth/login`
- `POST /api/auth/register`
- `POST /api/auth/forgot-password`
- `POST /api/auth/reset-password`
- `POST /api/auth/logout` (requiere token)

## Notas

- El flujo de recuperacion usa `password_reset_tokens`.
- El enlace de recuperacion apunta al front-end en `/auth/reset-password`.
