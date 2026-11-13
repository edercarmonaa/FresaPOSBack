# Entorno Windows con SQL Server Express

Etapa 1.2b: conservar .env y la conexión SQL local existente. Antes de la suite completa ejecutar scripts/setup-test-db.cmd con la instancia local. Usa .env.testing privado y TEST_DB_* para una base independiente terminada en _test, sin heredar la conexión local. Los ejemplos de despliegue solo tienen marcadores; setup-local genera los valores locales explícitamente si no existe .env. APP_ENV no configura por sí solo host, base ni credenciales: cada despliegue debe proveer sus propias variables. Detalles en [entorno-pruebas.md](entorno-pruebas.md).

Etapa 1.2a conserva este entorno y la autenticación Windows existente. PHP 8.3.36 necesita sqlsrv/pdo_sqlsrv 5.13.3 NTS x64, DOM, SimpleXML y las extensiones indicadas por Composer. Verificar con `scripts/local.cmd composer check-platform-reqs`. Los 13 módulos no necesitan una base adicional ni ejecutan SQL al arrancar; las pruebas modulares de esta etapa no consultan la base. `scripts/local.cmd php artisan module:list` verifica el registro y `scripts/local.cmd composer test` incluye las suites Feature, Architecture y Modules.

La decisión vigente es ejecutar localmente **sin Docker**, conforme a la instrucción posterior del usuario. Los archivos Compose se conservan como referencia; no son un requisito de aceptación de este entorno. Ver `entorno-docker-historico.md` para la propuesta anterior.

## Preparación

Requisitos Windows x64: SQL Server Express iniciado, Microsoft ODBC Driver 18 x64, PowerShell 5.1 y runtime Microsoft Visual C++ Redistributable 2015–2022 x64. Descargas oficiales: [ODBC](https://learn.microsoft.com/en-us/sql/connect/odbc/download-odbc-driver-for-sql-server) y [Visual C++](https://learn.microsoft.com/en-us/cpp/windows/latest-supported-vc-redist). El script verifica ODBC y se detiene si falta; no instala componentes del sistema ni modifica servicios.

```powershell
.\scripts\setup-local.cmd -SqlInstance '.\AUDITORIA'
.\scripts\local.cmd serve
```

La instancia detectada en este equipo es `AUDITORIA`, SQL Server Express 17.0.1155.3 (64-bit), consultada durante 1.2a. En otro equipo indicar su propia instancia. La conexión local verificada utiliza memoria compartida y autenticación integrada Windows; no se necesita SQL Browser, TCP abierto ni contraseña de sa. El proceso PHP debe ejecutarse con la cuenta Windows autorizada en SQL.

El script prepara PHP oficial 8.3.36 NTS x64 y extensiones SQLSRV/PDO_SQLSRV 5.13.3 para esa ABI, Composer 2.9.2 y certificados CA en `.tools/`. PHP, Composer y archivo de drivers tienen SHA-256 fijado. No instala ni cambia el PHP del sistema ni PATH global. Los wrappers ajustan variables solo durante el comando y las restauran.

El `.env` se crea si no existe; APP_KEY se genera solo si está vacía. Cambiar `-SqlInstance` en ejecuciones posteriores no sobrescribe `.env`: editar DB_HOST directamente. El script ejecuta Composer desde el lock sin omitir extensiones, limpia configuración y crea la base `fresapos` si falta. Si ya existe no elimina tablas ni datos. La cuenta necesita permiso para crear DB o el administrador debe crearla previamente y conceder acceso apropiado.

Los `.cmd` usan `powershell.exe -NoProfile -ExecutionPolicy Bypass` únicamente para el proceso del script local: no cambian la política global ni ejecutan scripts remotos. En entornos corporativos que impidan esa ejecución, solicitar al administrador el mecanismo aprobado y usar directamente el PHP preparado con los comandos del README.

## Ejecución

`local.cmd serve` abre solo 127.0.0.1:8080 con el servidor de desarrollo de Artisan; Ctrl+C lo detiene. No usarlo para producción. Para otro puerto ejecutar `local.cmd php artisan serve --host=127.0.0.1 --port=8081` y ajustar APP_URL.

Para invocar PHP: `local.cmd php ...`; para Composer: `local.cmd composer ...`. Estos wrappers evitan depender de un PHP distinto en PATH. Sesiones/cache son archivos, cola síncrona; no hay Node, Vite, Redis ni workers externos.

No existe un comando de reinicio destructivo de SQL. Repetir setup es seguro respecto a la base existente; para eliminar datos se requiere una decisión explícita del responsable. Respaldar `.env` y base por separado. Las bases ajenas no se modifican.

`APP_KEY` es secreto y `.env` permanece ignorado. Para Azure se mantienen ejemplos propios con `APP_DEBUG=false`, puerto 1433, credenciales SQL, Encrypt=yes y TrustServerCertificate=no. El cambio no requiere código.
# Imágenes de catálogo (1.4c)

El PHP portátil del proyecto requiere **GD con JPEG/PNG/WebP y EXIF** además de las extensiones SQL existentes. Las DLL ya incluidas se habilitan en `.tools/php/php.ini`; setup-local.ps1 conserva esta configuración para nuevas instalaciones. No se modifican `.env`, autenticación de Windows ni la conexión SQL. No hace falta una librería de imágenes Composer ni un ejecutable externo.

Valores locales: upload_max_filesize=8M, post_max_size=16M y memory_limit=256M. El límite funcional sigue en 5 MiB; se deja margen al cuerpo multipart y PHP. Reiniciar el proceso del servidor tras cambiar php.ini. Verificar con `local.cmd php --ri gd`, `local.cmd php --ri exif` y `local.cmd php -i`; los requisitos de GD/EXIF se verifican explícitamente y mediante las pruebas, además del check-platform-reqs de los requisitos Composer existentes.

La publicación del disco público en Windows se prepara con `powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\scripts\publish-catalog-storage.ps1`; comprueba el enlace/junction antes de crear y se detiene si existe otra ubicación o reglas distintas. En Linux/cPanel comprobar primero public/storage y su contenido y usar storage:link o equivalente autorizado. No publicar storage/app/private, donde permanecen los temporales y el journal de recuperación. Reglas Apache, adaptadores remotos y límites del hosting requieren validación propia; ver [informe de imágenes](entrega-1.4c.md).
