# FresaPOSBack

Etapa actual: **1.5b, API REST de mesas y ventas**. [Informe de Sales](docs/entrega-1.5b.md), [catálogo](docs/entrega-1.4d.md), [seguridad](docs/entrega-1.3c.md), [autenticación](docs/autenticacion.md) y [API](docs/api.md). La apertura y el agregado operativo requieren Cash e idempotencia durable; retirada/cancelación requieren motivos autorizados. Estas operaciones siguen bloqueadas mientras falten sus dependencias. Aún no hay pantalla POS.

Backend modular de FresaPOS para establecimientos de alimentos y bebidas. Entrega 1.1, versión 0.1.0: Laravel 13, PHP 8.3, SQL Server Express local y futura conexión a Azure SQL por entorno. Sin funcionalidades POS, Node, npm ni Vite.

## Entorno local Windows, sin Docker

Requisitos: Windows x64, PowerShell 5.1, SQL Server Express iniciado, Microsoft ODBC Driver 18 x64, Microsoft Visual C++ Redistributable 2015–2022 x64 y acceso a internet para descargar dependencias y Tailwind CDN. El equipo actual tiene instancia `.\AUDITORIA`; no se llama SQLEXPRESS. Se utiliza autenticación de Windows con la cuenta que ejecuta PHP.

Desde PowerShell en la raíz del proyecto:

```powershell
.\scripts\setup-local.cmd -SqlInstance '.\AUDITORIA'
.\scripts\local.cmd serve
```

Abrir <http://127.0.0.1:8080>. Detener el servidor de esa terminal con Ctrl+C. No exponer `artisan serve` a la red ni usarlo en producción.

La preparación descarga y verifica SHA-256 de PHP 8.3.36 NTS x64, Composer 2.9.2 y drivers Microsoft SQLSRV 5.13.3. Instala todo en `.tools/`, genera un php.ini propio, instala dependencias del lock **sin omitir requisitos**, crea `.env` solo si no existe, genera APP_KEY solo si está vacía y crea únicamente la base local vacía `fresapos`. No cambia PATH, servicios SQL, firewall ni política PowerShell de forma permanente. Los lanzadores CMD limitan ExecutionPolicy Bypass al proceso que ejecuta nuestros scripts.

En otro equipo usar el nombre real de la instancia, por ejemplo `-SqlInstance '.\SQLEXPRESS'`. Si `.env` ya existe, se conserva y el parámetro no lo sobrescribe: editar DB_HOST allí antes de repetir. La cuenta Windows necesita permiso de conexión y de creación de la base para la primera preparación; no se conceden permisos automáticamente.

## Comandos diarios

Antes de ejecutar la suite completa por primera vez, preparar la base independiente con `scripts/setup-test-db.cmd -SqlInstance '.\AUDITORIA'`. Las pruebas de integración usan fresapos_test y abortan ante una base insegura; requieren la cuenta Windows autorizada. No usan la base local fresapos. Ver [entorno de pruebas](docs/entorno-pruebas.md).

```powershell
.\scripts\local.cmd php -v
.\scripts\local.cmd composer install
.\scripts\local.cmd composer validate --strict
.\scripts\local.cmd composer check-platform-reqs
.\scripts\local.cmd composer test
.\scripts\local.cmd composer lint
.\scripts\local.cmd composer analyse
.\scripts\local.cmd php vendor/laravel/pint/builds/pint
.\scripts\local.cmd php artisan module:list
.\scripts\local.cmd php artisan db:show --database=sqlsrv
.\scripts\local.cmd php artisan optimize:clear
```

Las migraciones incrementales de Users, Auth, Audit y Catalog están aplicadas; `migrate:status` permite revisarlas. `db:show` comprueba la conexión real. Larastan usa nivel 5 y Pint preset Laravel.

Reinstalar dependencias: repetir `composer install`. Restablecer la configuración local: editar `.env` y ejecutar `artisan optimize:clear`. No hay reinicio destructivo de la base automatizado; las demás bases de tu SQL Express se conservan.

## Variables

| Variables | Uso |
| --- | --- |
| APP_NAME, APP_ENV, APP_KEY, APP_DEBUG, APP_URL | Identidad, cifrado, errores y URL; debug solo en local. |
| APP_LOCALE, APP_FALLBACK_LOCALE | Idioma y alternativa. |
| DB_CONNECTION | `sqlsrv`. |
| DB_HOST | Instancia Windows de tu entorno; `.env.example` tiene un marcador y setup-local genera el valor recibido. |
| DB_PORT | Vacío para instancia local; `1433` para Azure o puerto TCP explícito. |
| DB_DATABASE | Base del entorno; setup-local conserva fresapos para el desarrollo existente. Los ejemplos versionados solo contienen marcadores. |
| DB_USERNAME, DB_PASSWORD | Vacíos para autenticación integrada Windows. Para autenticación SQL/Azure, rellenar ambos en el entorno privado. |
| DB_ENCRYPT, DB_TRUST_SERVER_CERTIFICATE | `yes/yes` local; `yes/no` Azure. |
| SESSION_DRIVER, SESSION_LIFETIME, SESSION_ENCRYPT, SESSION_SECURE_COOKIE | Archivos, duración, cifrado y cookie segura; HTTPS fuera de local. |
| CACHE_STORE, QUEUE_CONNECTION | `file` y `sync`. |
| LOG_CHANNEL, LOG_STACK, LOG_LEVEL, MAIL_MAILER | Logs; sin integración de correo. |

APP_PORT se conserva como referencia del flujo Docker histórico; `local.cmd serve` usa 8080. `.env`, `.tools`, vendor y logs están ignorados; no subir secretos. Ejemplos staging/producción conservan conexión Azure y debug desactivado. No requieren cambios de código.

## Solución de problemas

- `could not find driver`: repetir setup y comprobar `local.cmd php -m`; deben aparecer `pdo_sqlsrv` y `sqlsrv`. No usar otro PHP del sistema por accidente.
- Error de carga DLL: comprobar PHP 8.3 NTS x64, runtime Visual C++ x64 y ODBC 18 x64; no mezclar drivers TS/x86.
- Login fallido: ejecutar desde tu sesión Windows autorizada. El sandbox y una cuenta de servicio distinta no heredan tus permisos SQL.
- Instancia no encontrada: comprobar servicio `MSSQL$AUDITORIA`, nombre real y protocolo disponible. Este equipo conecta por memoria compartida; no requiere activar SQL Browser ni abrir puertos.
- Permiso para crear base: pedir al administrador crear `fresapos` y otorgar el acceso mínimo; repetir setup después. No usar sa como solución automática.
- Puerto HTTP ocupado: detener la instancia anterior de este proyecto o ejecutar `local.cmd php artisan serve --host=127.0.0.1 --port=8081` y ajustar APP_URL.
- Sin estilos al perder conexión: limitación del CDN, descrita en docs/frontend.md.

## Documentación

La etapa [1.5a](docs/entrega-1.5a.md) prepara el dominio y persistencia de ventas de mostrador/mesas, líneas con precios históricos, cantidades enteras (máximo inicial 100), notas, transacciones y concurrencia SQL. **Todavía no añade una pantalla POS ni API de ventas. La apertura operativa requiere Cash funcional y permanece bloqueada mientras no exista esa integración.** No se crean ventas ni mesas ficticias en la base real.

[Entorno Windows](docs/entorno-desarrollo.md) · [Validación local](docs/validacion-local.md) · [Versiones](docs/versiones.md) · [Arquitectura](docs/arquitectura.md) · [SQL y Azure](docs/base-datos-azure.md) · [Módulos](docs/modulos.md) · [Frontend](docs/frontend.md) · [Hosting](docs/hosting.md).

Se conservaron los archivos Docker de la propuesta anterior como referencia, sin ejecutarlos ni requerirlos. [Informe histórico](docs/validacion.md). La instrucción posterior de usar Windows/SQL Express sustituye los criterios Docker para este entorno local. Las etapas 1.2a–1.2c, 1.3a–1.3c y 1.4a están implementadas y validadas localmente.

La entrega [1.4b](docs/entrega-1.4b.md) incorpora la API `/api/v1/catalog` y las pantallas [Productos](http://127.0.0.1:8080/admin/catalog/products) y [Categorías](http://127.0.0.1:8080/admin/catalog/categories), accesibles tras iniciar sesión con permisos administrativos de catálogo. El apartado «Catálogo» aparece en el layout existente según los permisos actuales. Permite registrar datos reales, editar precios, cambiar estados y utilizar la papelera de productos. No se crearon datos de ejemplo en la base operativa. La entrega [1.4c](docs/entrega-1.4c.md) añade imagen principal opcional: subida JPEG/PNG/WebP de hasta 5 MiB, optimización WebP, vista previa, sustitución, eliminación y recuperación de archivos pendientes. Usa GD y EXIF nativos. Contratos y ejemplos en [OpenAPI](docs/openapi.yaml) y [API](docs/api.md). La [validación integral 1.4d](docs/entrega-1.4d.md) documenta el cierre local, la validación formal, la revisión visual en cuatro tamaños con [44 capturas](docs/qa/1.4d/README.md) y los pendientes de despliegue. No se implementa Sales.

Para publicar el disco local en Windows, ejecutar `powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\scripts\publish-catalog-storage.ps1`. El script comprueba rutas existentes y no sobrescribe enlaces o reglas distintos. La subida temporal requiere sesión y permiso de edición; las imágenes finales son públicas. Consultar el informe antes de desplegar reglas Apache, GD/EXIF o un adaptador remoto.

Validación OpenAPI de desarrollo (Python 3.12, sin Node/Docker ni paquetes PHP nuevos): preparar una vez con `powershell.exe -NoProfile -ExecutionPolicy Bypass -File scripts/setup-openapi.ps1 -PythonPath RUTA_AL_PYTHON_EXE`; después ejecutar `.\scripts\local.cmd composer openapi`. El entorno y las dependencias fijadas permanecen en `.tools/openapi`, fuera del hosting y de Git. Para contrastar respuestas reales, ejecutar la suite y el comando ampliado del [informe 1.4d](docs/entrega-1.4d.md).
