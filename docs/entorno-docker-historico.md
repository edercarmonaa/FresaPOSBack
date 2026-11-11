# Referencia histórica: flujo Docker de la primera entrega

El usuario sustituyó este flujo por Windows nativo con SQL Express. No se requiere Docker. El contenido siguiente conserva la propuesta original; sus comandos no corresponden al .env.example local actual.

# FresaPOSBack

Backend de FresaPOS para establecimientos de alimentos y bebidas: futura administración, POS web y API para FresaPOSApp (Kotlin). Entrega 1.1, versión 0.1.0: base Laravel modular y entorno de desarrollo. Sin funcionalidad de negocio ni autenticación.

PHP 8.3.x, Laravel 13, SQL Server local / Azure SQL por entorno, laravel-modules, Livewire y Tailwind CDN. Sin Node, npm ni Vite. Versiones, requisitos reales y fuentes: [docs/versiones.md](docs/versiones.md).

## Requisitos

Docker Engine/Desktop con Compose v2 y contenedores Linux; CPU x86_64 y al menos 4 GB de memoria asignados a Docker (SQL Server requiere al menos 2 GB). Acceso a registros de imágenes, Composer, Debian, Microsoft y CDN. Puertos HTTP locales disponibles. No necesitas PHP ni Composer instalados en el equipo.

SQL Server oficial usa Ubuntu, derivado de Debian; no existe imagen oficial Debian. Apple Silicon requiere emulación amd64 para SQL Server, que Microsoft no soporta oficialmente: preferir servidor x86_64 remoto o Azure. Detalles en [entorno](docs/entorno-desarrollo.md).

## Levantar desde cero

Comandos para shell Debian desde la raíz del proyecto. En PowerShell sustituir `cp` por `Copy-Item`; los comandos Docker son iguales.

```sh
cp .env.example .env
# Editar .env: poner la misma contraseña local fuerte y propia en
# MSSQL_SA_PASSWORD y DB_PASSWORD; mínimo 8 caracteres y complejidad SQL Server.
docker compose build
docker compose up -d sqlsrv
docker compose run --rm --no-deps app composer install --no-interaction --prefer-dist
docker compose run --rm --no-deps app php artisan key:generate
# El siguiente comando espera a SQL Server sano e inicia app.
docker compose up -d app
docker compose exec app php scripts/init-local-db.php
docker compose up -d web
docker compose ps
docker compose exec app php artisan db:show --database=sqlsrv
```

Abrir <http://localhost:8080>. La inicialización crea únicamente la base vacía, no tablas de negocio. En esta entrega no hay migraciones: `migrate:status` puede informar que no existe la tabla de migraciones; usar `db:show` como comprobación real de conexión. No usar el script de creación local contra Azure.

No subir `.env`, claves ni `vendor`. `CHANGE_ME` es un marcador que debes sustituir; SQL Server rechazará ese valor. `APP_KEY` se genera con Artisan. En Debian, los directorios montados deben permitir escritura al UID 1000 de app; ajustar propietario/ACL de `storage`, `bootstrap/cache` y `vendor` para ese usuario, sin permisos 777. En Windows Docker Desktop administra el montaje.

## Operación y calidad

```sh
docker compose up -d                         # iniciar
docker compose stop                         # detener conservando contenedores
docker compose down                         # quitar contenedores, conservar SQL
docker compose build --no-cache app          # reconstruir
docker compose up -d --force-recreate        # recrear servicios
docker compose logs --tail=100 app web sqlsrv # diagnosticar
docker compose exec app php -v
docker compose exec app composer validate --strict
docker compose exec app composer test
docker compose exec app composer lint
docker compose exec app composer analyse
docker compose exec app vendor/bin/pint      # aplicar formato
docker compose exec app php artisan module:list
```

Reiniciar SQL desde cero **borra todos los datos locales**: respaldar primero y ejecutar `docker compose down --volumes`, después repetir desde `docker compose up -d sqlsrv` y crear nuevamente la base con el script. No borra `.env` ni dependencias montadas.

Larastan inicia en nivel 5 (errores de tipos y llamadas comunes, sin imponer aún la disciplina de nivel máximo). Las pruebas de arranque no requieren base; la prueba de conexión real es independiente.

## Variables de entorno

| Variables | Uso |
| --- | --- |
| APP_NAME, APP_ENV, APP_KEY, APP_DEBUG, APP_URL, APP_LOCALE, APP_FALLBACK_LOCALE | Identidad, entorno, cifrado, errores y URL; debug desactivado fuera de local. |
| APP_PORT | Puerto HTTP publicado exclusivamente en 127.0.0.1. |
| DB_CONNECTION | `sqlsrv`. |
| DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME, DB_PASSWORD | Servidor y credenciales; local usa nombre de servicio `sqlsrv`. |
| DB_ENCRYPT, DB_TRUST_SERVER_CERTIFICATE | `yes/yes` local; `yes/no` Azure, validando CA y nombre del servidor. |
| MSSQL_SA_PASSWORD | Administrador del contenedor local; no necesario en hosting/Azure. |
| SESSION_DRIVER, SESSION_LIFETIME, SESSION_ENCRYPT, SESSION_SECURE_COOKIE | Sesiones en archivos y cookies; HTTPS y cookie segura en staging/producción. |
| CACHE_STORE, QUEUE_CONNECTION | `file` y `sync`; sin servicios externos. |
| LOG_CHANNEL, LOG_STACK, LOG_LEVEL, MAIL_MAILER | Logs locales; correo en log, sin integración de correo. |

Perfiles: [.env.staging.example](.env.staging.example), [.env.production.example](.env.production.example). [Azure SQL](docs/base-datos-azure.md) y [hosting/despliegue](docs/hosting.md).

## Solución de problemas

- SQL unhealthy: revisar memoria, arquitectura y logs; comprobar contraseña fuerte. Cambiar la variable no cambia la contraseña del volumen existente: conservar la credencial original o restablecerla en SQL; reiniciar el volumen solo si sus datos son descartables.
- `could not find driver`: verificar `php -m`, imagen reconstruida y ODBC; ver hosting.
- Error de certificado: local admite `DB_TRUST_SERVER_CERTIFICATE=yes`; en Azure corregir CA, DNS o reloj, conservar validación activa.
- Error de login/base: verificar credenciales y ejecutar inicialización local después del healthcheck. El uso de `sa` es exclusivo del entorno local.
- 502 / permisos: revisar salud de FPM y escritura en `storage` / `bootstrap/cache`; `docker compose logs app web`.
- Configuración antigua: `docker compose exec app php artisan optimize:clear` tras modificar `.env`.
- Sin estilos al perder internet: limitación del CDN; [frontend](docs/frontend.md).

## Documentación y estado

[Arquitectura](docs/arquitectura.md) · [Módulos](docs/modulos.md) · [Validación real y pendientes](docs/validacion.md) · [Contribuir](CONTRIBUTING.md) · [Seguridad](SECURITY.md). La validación Docker/SQL está pendiente; no se declara aceptada esta entrega hasta ejecutarla. No hay decisiones de negocio ni trabajo de la entrega 1.2.
