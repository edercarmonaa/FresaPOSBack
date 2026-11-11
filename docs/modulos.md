# Registro y mantenimiento de módulos

Actualización 1.2b: Core tiene un contrato productivo TransactionManager y un adaptador en Infrastructure/Persistence, registrados en su proveedor. Las carpetas se crearon con contenido real. La suite Integration de la raíz utiliza fresapos_test, con guardas y tablas efímeras; por eso la suite completa ahora requiere preparar esa base. Las pruebas modulares originales conservan su aislamiento sin SQL. No se modificó la matriz de dependencias.

Se usa laravel-modules 13.3.0 con Laravel 13.42.0 y PHP 8.3.36. El manifiesto instalado requiere PHP ^8.3, DOM, JSON, SimpleXML y merge-plugin; su rama y dependencias de desarrollo corresponden a Laravel ^13.0. Las pruebas de integración verifican la combinación real. No fue necesario actualizar paquetes. Fuente: [manifest oficial v13.3.0](https://github.com/nWidart/laravel-modules/blob/v13.3.0/composer.json).

Los 13 módulos registrados son Core, Auth, Users, Catalog, Sales, Cash, Tickets, Reports, Audit, Notifications, Devices, Settings y System. Todos están habilitados en `modules_statuses.json`; cada `module.json` declara su proveedor principal. Las responsabilidades, reglas y límites del análisis automático están en [arquitectura.md](arquitectura.md).

## Generación oficial mínima

Se comprobó cuáles faltaban y se ejecutó el comando oficial `module:make` solo para ellos, sin `--force`. Se usa el modo normal con stubs mínimos configurados, porque `--plain` en esta versión elimina el proveedor y omite composer.json: no bastaría para el registro solicitado.

```powershell
.\scripts\local.cmd php artisan module:make Core Auth Users Catalog Sales Cash Tickets Reports Audit Notifications Devices Settings System --no-interaction
.\scripts\local.cmd composer dump-autoload --optimize
.\scripts\local.cmd php artisan module:list
```

No repetir la creación sobre módulos existentes. Para un módulo nuevo autorizado, pasar únicamente su nombre. El generador invoca Composer al terminar; `scripts/composer.cmd` permite encontrar el Composer portátil mediante el PATH temporal de `local.ps1`. No requiere instalación global ni modifica PATH del sistema.

`config/modules.php` apunta a `stubs/modules`; las plantillas no presentes allí se obtienen del paquete. Los stubs locales generan composer modular, proveedor mínimo, proveedor de rutas web y archivo de rutas vacío. Se desactiva generación de controladores, assets, seeders, factories y proveedor de eventos de ejemplo. El generador no crea API de ejemplo; la etapa 1.2c añade de forma expresa routes/api/v1.php únicamente en Core y el agregador raíz versionado, según [api.md](api.md). No hay vistas de ejemplo, package.json ni Vite.

## Registro y pruebas

- El proveedor principal hereda ModuleServiceProvider del paquete. Registra configuración y namespaces de recursos/vistas, y carga RouteServiceProvider del mismo módulo.
- El proveedor de rutas mapea el archivo web vacío mediante middleware web; no registra endpoints de negocio.
- `modules.auto-discover.migrations=true` añade el directorio de cada módulo al migrador sin ejecutar operaciones SQL.
- `composer.json` de cada módulo declara `Modules\Name\` hacia app y `Modules\Name\Tests\` hacia tests. El merge-plugin combina esos manifests desde la raíz. No se registran proveedores duplicados en bootstrap/providers.php ni en extra.laravel.providers de los módulos.
- PHPUnit tiene suite Modules y descubre los tests reales del módulo. Core contiene una prueba de DI y fixtures aisladas del autoload productivo. Los otros módulos solo tienen estructura vacía de pruebas, no tests de ejemplo.
- Larastan mantiene nivel 5 y analiza Modules, excluyendo sus tests como ya sucedía con los tests de la raíz. Pint incluye los módulos y las pruebas.

Comandos: `local.cmd composer test`, `local.cmd composer lint`, `local.cmd composer analyse`, precedidos de la ruta scripts como en README. Las pruebas de 1.2a no consultan ni alteran SQL. La comprobación manual de la conexión existente usa `local.cmd php artisan db:show --database=sqlsrv` y no crea una base de pruebas nueva.

## Evolución controlada

Antes de añadir un import cruzado, revisar la matriz `config/module-dependencies.php`, justificar la relación y mantener el grafo acíclico. Actualmente solo está permitido apuntar a Core y únicamente por su superficie pública; no existen referencias productivas cruzadas todavía. No convertir permisos en dependencias obligatorias de arranque sin un caso de uso.

Crear Domain/Application/Infrastructure/Http y Contracts únicamente al agregar su primer archivo real. No añadir carpetas vacías para aparentar arquitectura. Al agregar un test modular, comprobar que aparece en `local.cmd composer test -- --list-tests` o invocar directamente el ejecutable PHPUnit con --list-tests.
