# Informe histórico de entrega 1.1

El entorno vigente es Windows nativo con SQL Express, sin Docker. Los resultados que sustituyen los pendientes locales de este informe están en [validacion-local.md](validacion-local.md).

Fecha: 11 de noviembre de 2026. **Implementación preparada; aceptación Docker/SQL pendiente.**

## Inspección inicial (solo lectura)

`C:\Repos\FresaPOSBack` estaba vacío, sin Git, Laravel, composer.json, Docker, `.env*`, licencia ni instrucciones AGENTS.md existentes. No hubo conflictos con código previo. No se preservaron aplicaciones ajenas ni se modificaron otros proyectos.

Se acordó por el alcance crear Laravel, Compose, conexión, módulos, layout, calidad y gobernanza en ese orden. No se incorporó negocio, Node/Vite/npm, Redis ni colas externas. Versiones consultadas antes de instalar: `versiones.md`.

## Archivos creados por propósito

- Base: `artisan`, `app/Http/Controllers/Controller.php`, `app/Providers/AppServiceProvider.php`, `bootstrap/app.php`, `bootstrap/providers.php`, configuración estándar, `public/` y estructura escribible de storage. Esqueleto Laravel oficial v13.10.1; se excluyeron Node, plantillas de negocio, User y migraciones estándar.
- Dependencias: `composer.json`, `composer.lock` (107 paquetes con desarrollo), con plataforma PHP 8.3.36 y scripts `test`, `lint`, `analyse`.
- Infraestructura: `compose.yaml`, `docker/php/Dockerfile`, `docker/php/app.ini`, `docker/nginx/default.conf`, `scripts/init-local-db.php`.
- Entorno y datos: `.env.example`, `.env.staging.example`, `.env.production.example`, `config/database.php`, `database/migrations/.gitkeep`. Defaults de sesión/cache por archivos y cola síncrona.
- Módulos: `config/modules.php`, `modules_statuses.json`, `Modules/.gitkeep`; sin módulos de negocio ni de ejemplo.
- Frontend: `routes/web.php`, `resources/views/layouts/app.blade.php`, `resources/views/welcome.blade.php`.
- Calidad: `phpunit.xml`, `tests/TestCase.php`, `tests/Feature/BootstrapTest.php`, `pint.json`, `phpstan.neon` (nivel 5).
- Gobernanza: README, VERSION, CHANGELOG, CONTRIBUTING, SECURITY, LICENSE MIT canónica y documentos en español en docs; `.gitignore`, `.dockerignore`, `.editorconfig`, `.gitattributes`.

Todos son archivos nuevos para este workspace. Las modificaciones al esqueleto descargado corresponden a esta entrega; no había archivos del usuario que sobrescribir. No se creó commit, remoto ni publicación. `.tools/` y `vendor/` contienen solo herramientas/dependencias locales ignoradas y no son entregables versionables. No hay `.env` con credenciales reales.

## Validaciones ejecutadas

PHP y Composer no estaban en PATH, y Docker no está instalado ni en su ruta habitual de Docker Desktop. Se descargaron PHP Windows NTS oficial, Composer y CA en `.tools/`, verificando SHA-256 del ZIP PHP contra el índice oficial. Los comandos PHP siguientes usaron ese PHP portátil, **no un contenedor**.

| Comando / comprobación | Resultado observado |
| --- | --- |
| `php -v` local portátil | PHP 8.3.36 CLI NTS. |
| `composer update --no-interaction --prefer-dist --ignore-platform-req=ext-sqlsrv --ignore-platform-req=ext-pdo_sqlsrv` | Instalación efectiva y lock generados, 107 paquetes. Se omitieron solo las dos extensiones SQL faltantes; no se omitió la versión PHP. |
| `composer update --lock --no-install` con las mismas dos excepciones | Lock sincronizado tras ajustar restricciones semánticas; ninguna versión cambió. Sin avisos de vulnerabilidad en esa consulta. |
| `composer validate --strict` | Correcto. Se corrigieron avisos iniciales por restricciones exactas usando rangos y lock fijo. |
| `php artisan vendor:publish --provider='Nwidart\Modules\LaravelModulesServiceProvider' --tag=config` | Configuración publicada; después se desactivaron stubs de herramientas frontend. |
| `composer test` | Correcto: 3 pruebas, 14 aserciones. Arranque, salud HTTP, layout, respuesta JS local de Livewire, proveedor y comando módulos. |
| `vendor/bin/pint` (ejecutado mediante PHP) | Formato aplicado. |
| `composer lint` | Correcto: Pint passed. |
| `composer analyse` | Correcto: Larastan sin errores, incluyendo scripts (6 archivos analizados). |
| `php artisan module:list` | Correcto: listado vacío, sin módulos de negocio. |
| `php artisan db:show --database=sqlsrv` en PHP portátil | Falló: `could not find driver`. No hay PDO_SQLSRV en ese runtime ni SQL Server local. |
| `composer check-platform-reqs --no-dev` | Falló exclusivamente por `ext-pdo_sqlsrv` y `ext-sqlsrv` ausentes. PHP y restantes requisitos informados pasan. |
| Consulta HTTP de tags PHP/nginx y lista MCR | PHP 8.3.36-fpm-bookworm y nginx 1.28.0-bookworm activos; tag SQL seleccionado presente. Esto no es una prueba de construcción ni ejecución. |
| Revisión de `.gitignore` | Se comprobó exclusión de `.env`, vendor y .tools y conservación de ejemplos. No hay secretos reales en los archivos de entrega. |

Se ejecutó además una instalación real separada en `.tools/release-check` con `composer install --no-dev --optimize-autoloader --prefer-dist --no-interaction --ignore-platform-req=ext-sqlsrv --ignore-platform-req=ext-pdo_sqlsrv`: **78 paquetes instalados, autoload optimizado y package discovery correctos**. Una petición al kernel HTTP de esa copia en APP_ENV=production, con clave ficticia y sesiones de prueba en memoria, devolvió **200 y marcado Livewire presente**, sin PHPUnit/Larastan/Pint instalados. Esta prueba demuestra independencia de paquetes de desarrollo, pero no conectividad SQL ni cumplimiento de plataforma del hosting. Las dos excepciones de drivers son exclusivas de esta comprobación Windows y no se deben usar en un release real.

La primera prueba de módulos usaba el helper de Artisan que requiere Mockery; se sustituyó por `Artisan::call`, evitando una dependencia innecesaria, y las pruebas pasaron. La validación del layout comprueba render y entrega HTTP del JS; no se ejecutó navegador ni se afirma ejecución cliente/offline.

## Validaciones pendientes

| Comando esperado | Estado / motivo |
| --- | --- |
| `docker compose build` | **No ejecutado:** comando Docker ausente. Pendiente comprobar snapshot APT, compilación PECL y construcción real. |
| `docker compose up -d` y `docker compose ps` | **No ejecutados:** Docker ausente; salud de los tres servicios pendiente. |
| `docker compose exec app php -v` | **No ejecutado:** no hay contenedor; PHP 8.3.x dentro de Docker pendiente. |
| `docker compose exec app php artisan db:show --database=sqlsrv` | **No ejecutado:** sin Docker/SQL local. Conexión y creación idempotente de base pendientes. |
| `docker compose exec app composer check-platform-reqs` y comandos de calidad | **No ejecutados dentro de contenedor:** solo realizados con PHP portátil según tabla. |
| Conexión Azure / hosting web | **No ejecutada:** faltan proveedor, recursos, credenciales, red y evidencia de drivers. |

No se declara que `compose up` o SQL funcionen hasta completar esas verificaciones. El procedimiento desde cero está en README.

## Riesgos y confirmaciones

Críticos: hosting con PHP web 8.3 y ambos drivers + ODBC 18; red/firewall de Azure e IP de salida. Tailwind CDN requiere internet y no es una estrategia productiva recomendada. SQL ARM emulado no tiene soporte oficial; Developer no puede usarse como licencia de producción. Las imágenes externas no sustituyen artefactos archivados por digest.

No hay una decisión de código bloqueada pendiente de confirmación. Antes del despliegue se requiere confirmar capacidades del hosting, infraestructura Azure, titular legal MIT y canal privado de seguridad. Cualquier contratación/migración de hosting o inicio de entrega 1.2 requiere autorización posterior. Se detiene el trabajo al finalizar 1.1.
