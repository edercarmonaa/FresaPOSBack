Etapa 1.2b: se conservan las versiones instaladas y composer.lock. ULID, transacciones y eventos posteriores al commit usan Laravel nativo; no se añaden dependencias. Validación SQL real documentada en entrega-1.2b.md.

# Versiones verificadas — 11 de noviembre de 2026

Etapa 1.2a: se verificó la instalación real Laravel 13.42.0 + laravel-modules 13.3.0 y su manifiesto oficial para Laravel 13/PHP 8.3. No se actualizaron ni añadieron dependencias. El análisis de imports en PHPUnit utiliza nikic/php-parser ya presente en el lock de desarrollo de PHPUnit/Larastan; no se instaló Deptrac.

## Actualización: entorno principal Windows sin Docker

PHP 8.3.36 NTS x64, Composer 2.9.2 y drivers SQLSRV/PDO_SQLSRV 5.13.3 Windows están preparados en .tools. ODBC Driver 18 x64 ya existe en el equipo. Se utiliza SQL Express 17.0.1145.4, instancia AUDITORIA, con autenticación Windows. Todos los requisitos Composer pasan sin excepciones. El script setup-local.ps1 fija descargas y verifica SHA-256. Las imágenes Docker siguientes son referencia histórica, no el runtime local activo.

Selección previa a instalar dependencias. El lock de Composer fija también las dependencias transitivas; `config.platform.php=8.3.36` impide resolver paquetes que requieran PHP superior. El proyecto restringe PHP a 8.3.x para reflejar el hosting.

| Componente | Versión elegida | Evidencia y motivo |
| --- | --- | --- |
| PHP | 8.3.36, imagen `php:8.3.36-fpm-bookworm` | Misma rama y parche que el hosting; Debian 12. PHP portátil Windows 8.3.36 usado solo para validación. |
| Laravel framework | 13.42.0 | Última estable consultada en [Packagist](https://packagist.org/packages/laravel/framework); requiere ^8.3. [Soporte oficial](https://laravel.com/docs/13.x/releases). Esqueleto oficial laravel/laravel v13.10.1. |
| laravel-modules | 13.3.0 | [Manifest oficial](https://github.com/nWidart/laravel-modules/blob/v13.3.0/composer.json): PHP ^8.3, DOM, SimpleXML y merge-plugin; desarrollo y Testbench para Laravel 13. |
| Livewire | 4.4.8 | [Manifest](https://github.com/livewire/livewire/blob/v4.4.8/composer.json): PHP ^8.1, Illuminate 10–13; compatible con esta selección. |
| Larastan | 3.14.0 | [Manifest](https://github.com/larastan/larastan/blob/v3.14.0/composer.json): PHP ^8.2, Illuminate ^13 y PHPStan ^2.3.0. |
| PHPUnit | ^12.5.12 | Rama 12 compatible con PHP 8.3; no usar PHPUnit 13, que exige PHP superior. Versión exacta en composer.lock. |
| Pint | ^1.27 | Herramienta de estilo Laravel; versión resuelta y requisitos comprobados por Composer en PHP 8.3. |
| Composer | 2.9.2 | Versión fijada para construcción y herramienta portátil. |
| sqlsrv / pdo_sqlsrv | 5.13.3 | [Release Microsoft](https://github.com/microsoft/msphpsql/releases/tag/v5.13.3) y [PECL](https://pecl.php.net/package/sqlsrv/5.13.3): PHP >=8.3; ambas se instalan separadamente con PECL, conforme al alcance solicitado. PECL está deprecado; PIE es la futura ruta recomendada por Microsoft. |
| msodbcsql18 | 18.7.1.1-1 | Disponible para amd64 y arm64 en el [índice oficial Debian 12](https://packages.microsoft.com/debian/12/prod/pool/main/m/msodbcsql18/). Cifrado explícito por entorno. |
| unixodbc-dev | 2.3.11-2+deb12u1 | Rama Debian Bookworm, superior al mínimo recomendado para pooling. |
| SQL Server | `2022-CU27-GDR2-ubuntu-22.04` | Tag fijo comprobado en el [registro oficial MCR](https://mcr.microsoft.com/v2/mssql/server/tags/list); Developer solo para desarrollo. Microsoft no publica imagen Debian: la imagen oficial usa Ubuntu (derivado de Debian). |
| nginx | 1.28.0-bookworm | Servidor web separado para FPM; base Debian, sin servidor de desarrollo Artisan expuesto. |
| Tailwind browser | 4.1.18 | [Paquete versionado](https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4.1.18/package.json). Sin Node ni compilación. |

No se detectó incompatibilidad declarada entre los paquetes elegidos. La resolución efectiva y las pruebas se registran en `validacion.md`. No equivale a haber construido Docker: los tags PHP/nginx se comprobaron activos mediante Docker Hub; instalación APT, extensiones e imágenes en ejecución deben comprobarse con `docker compose build`. Los parches APT se fijan mediante snapshot Debian; los repositorios externos y tags pueden desaparecer o cambiar. Para reproducibilidad binaria futura, conservar imágenes en registro propio y fijar sus digests tras validarlas.
