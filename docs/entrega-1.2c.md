# Informe de entrega — etapa 1.2c y cierre local de 1.2

Fecha de ejecución: 11 de noviembre de 2026. Alcance: infraestructura REST v1, sin negocio, autenticación real ni avance a 1.3.

## A. Estado inicial y línea base

Se verificaron los prerrequisitos en archivos y mediante la suite existente: 1.2a con 13 módulos habilitados, proveedores y dependencias acíclicas; 1.2b con ULID nativo nchar(26), contrato transaccional, SQL Server independiente y salvaguardas. Se reutilizaron, sin reconstrucción. Informes anteriores: [1.2a](entrega-1.2a.md) y [1.2b](entrega-1.2b.md).

Versiones conservadas: PHP 8.3.36, Laravel 13.42.0, laravel-modules 13.3.0, Livewire 4.4.8, PHPUnit 12.5.43, Pint 1.32.2 y Larastan 3.14.0. Sanctum no figura instalado ni en el lock; no se instaló. No había componentes ni rutas de API, ni parser YAML disponible en el proyecto. El locale operativo es es con fallback en; las pruebas declaran el idioma expresamente porque el perfil testing no define APP_LOCALE.

Antes de modificar archivos se ejecutó desde PowerShell con `.\scripts\local.cmd`:

| Comando | Línea base real |
| --- | --- |
| `composer test` | 26 pruebas, 296 aserciones correctas. |
| `composer lint` | Pint passed. |
| `composer analyse` | Nivel 5, 61 archivos, sin errores. |
| `composer validate --strict` | composer.json válido. |
| `git status --short` | No ejecutable como revisión Git: la carpeta no contiene repositorio Git. No se inicializó. |

Se presentó el plan antes de editar y el usuario respondió «si». El punto de control recomendó un enum de códigos y un registro de mapeos de excepciones, evitando acoplar el dominio al estado HTTP. La implementación sigue esa decisión.

## B. Cambios y dependencias

| Área | Archivos |
| --- | --- |
| Contratos públicos de Core | `Modules/Core/app/Contracts/{ApiCode,ApiResponder,ApiExceptionMappings}.php` |
| Adaptación HTTP interna | `Modules/Core/app/Http/{ApiJsonResponse,ApiRequestContext,ApiResponseFactory,ApiExceptionHandler,ExceptionMappings}.php` |
| Middleware global condicionado | `Modules/Core/app/Http/Middleware/AssignApiRequestId.php` |
| Convención Form Request | `Modules/Core/app/Http/Requests/ApiFormRequest.php` |
| Traducciones aisladas de API | `Modules/Core/lang/{es,en}/{api,validation}.php` |
| Composición y limitador | `Modules/Core/app/Providers/CoreServiceProvider.php` |
| Integración con Laravel | `bootstrap/app.php` |
| Agregador y único endpoint | `routes/api.php`, `Modules/Core/routes/api/v1.php` |
| Pruebas nuevas | `tests/Feature/ApiConventionsTest.php`, `tests/Fixtures/Api/{ProbeRequest,ProbeConflict}.php` |
| Documentación | `docs/api.md`, `docs/openapi.yaml`, este informe; actualizaciones de arquitectura, módulos, README y CHANGELOG. |

No se añaden ni actualizan dependencias. Composer y su lock permanecen intactos. No se cambian configuración SQL, perfiles de entorno, CORS, migraciones, vistas, assets, pruebas anteriores ni la matriz de dependencias. Las carpetas nuevas tienen contenido real. Los archivos auxiliares de evidencia bajo .tools están ignorados y no pertenecen al producto.

## C. Arquitectura resultante de 1.2

**1.2a:** Core, Auth, Users, Catalog, Sales, Cash, Tickets, Reports, Audit, Notifications, Devices, Settings y System siguen habilitados. Se conservan sus proveedores, recursos y autoload. Core no depende de módulos funcionales; los demás solo pueden acceder a sus Contracts o servicios públicos de Application/Services. El escáner y sus límites semánticos permanecen intactos.

**1.2b:** se mantienen HasUlids y Blueprint::ulid nativos, convenciones SQL, TransactionManager y su adaptador Laravel; SQL Express local con Windows Authentication, perfiles posteriores de Azure y fresapos_test con guardas previas al DDL. Las pruebas SQL existentes siguen verificando ULID, índices, serialización, FK, precisión decimal, UTC, commit, rollback y eventos después del commit. No existen tablas permanentes de negocio.

**1.2c:** la raíz aplica una sola vez api, agrega v1 y carga archivos de API relativos de módulos habilitados. El nombre del endpoint es api.v1.core.ping. Un futuro v2 se añadirá como grupo y archivos separados, preservando v1. Ping es una closure mínima sin controlador ficticio, no consulta SQL ni sustituye /up.

Core ofrece contratos públicos de respuesta, códigos y mapeos; su Http permanece interno. Los mensajes proceden de diccionarios es/en, con códigos estables separados del HTTP. El normalizador mantiene los sobres y sanea errores; el finalizador de Laravel cubre también excepciones con respuesta propia. El registro transforma excepciones específicas de aplicación/dominio en códigos seguros; las desconocidas y QueryException son 500 genéricos.

El middleware es global y condicionado a API para alcanzar 404 sin ruta y errores tempranos. Valida estrictamente el ID entrante o genera ULID nativo, comparte el contexto de logs y lo limpia al salir. No añade la cabecera a web ni Livewire. Las respuestas 5xx registran su ID; los detalles técnicos de excepciones quedan en el log, incluso con debug activo. El ID no autentica ni proporciona idempotencia.

Se conserva la semántica nativa de HTML y Livewire. Los errores de otras rutas que esperan JSON también reciben el sobre seguro, excluyendo Livewire; su middleware de correlación no se aplica globalmente fuera de API. El limitador mínimo es 60/minuto por IP con el caché existente. Las cabeceras Allow y Retry-After se preservan. Los detalles y límites HTTP están en [api.md](api.md).

## D. Contratos con ejemplos reales

Las siguientes respuestas proceden de solicitudes HTTP ejecutadas contra el servidor existente en 127.0.0.1:8080, enviando `X-Request-Id: delivery-1_2c-real`. Se presenta el JSON decodificado e indentado; no se fabricaron IDs ni cuerpos. Evidencia auxiliar: .tools/api-http-evidence.json.

`GET /api/v1/ping`: **HTTP 200**, cabecera `X-Request-Id: delivery-1_2c-real`:

```json
{
  "success": true,
  "code": "OPERATION_SUCCESS",
  "message": "Operación completada correctamente.",
  "data": {"status": "ok"},
  "meta": {"request_id": "delivery-1_2c-real"}
}
```

`GET /api/v1/no-existe`: **HTTP 404**, misma cabecera:

```json
{
  "success": false,
  "code": "NOT_FOUND",
  "message": "El recurso solicitado no existe.",
  "meta": {"request_id": "delivery-1_2c-real"}
}
```

El mismo cliente repitió deliberadamente un ID válido, que el servidor preservó. La suite verifica que dos solicitudes sin ID generan ULID distintos. Los errores 500 y de validación se ejecutan exclusivamente con rutas efímeras de PHPUnit; no se dejó una ruta pública que produzca fallos para documentarlos.

## E. Pruebas, resultados y límites

Comandos ejecutados desde PowerShell en la raíz, usando el lanzador portátil. Las pruebas SQL requieren la cuenta Windows autorizada; el proceso restringido no sustituye su identidad.

| Comando o comprobación | Resultado final real |
| --- | --- |
| `.\scripts\local.cmd composer test` | **55 pruebas, 558 aserciones, todas correctas**; antes 26/296. |
| `.\scripts\local.cmd composer lint` | **Pint passed**. Se aplicó formato solo a archivos nuevos o modificados de esta entrega. |
| `.\scripts\local.cmd composer analyse` | **Sin errores, nivel 5, 77 archivos**; antes 61. No se rebajó nivel ni añadieron exclusiones. |
| `.\scripts\local.cmd composer validate --strict` | **composer.json válido**. |
| `.\scripts\local.cmd php artisan route:list --path=api` | Únicamente GET/HEAD api/v1/ping, nombre api.v1.core.ping. |
| HTTP real GET /api/v1/ping | 200, sobre y cabecera coincidentes. |
| HTTP real GET /api/v1/no-existe | 404 uniforme con ID. |
| HTTP real GET /api/v1/_test/runtime | 404: las rutas de fixture no existen en la aplicación normal. |
| HTTP real GET /, /up y JavaScript local Livewire | Los tres 200; web y /up sin X-Request-Id. |
| Comparación SHA-256 | .env, composer.lock y todos los archivos de pruebas anteriores conservan los hashes iniciales. |
| Consultas de solo lectura a sys.tables | fresapos: 0 tablas de usuario; fresapos_test: 0 después de limpiar fixtures. |

Las 29 pruebas nuevas cubren rutas y DI del constructor de respuestas, idiomas es/en, sobres exactos, HTTP 400/401/403/404/405/409/422/429/500/503, validación anidada y mensajes españoles, guard sin login, Gate, excepción de dominio mapeada, Allow y Retry-After, throttle real, 404 sin middleware de ruta y rutas de otra versión.

También verifican ID válido de 64 caracteres, rechazo de vacío/65 caracteres/espacios/barra/Unicode/CRLF, generación de ULID distintos, coincidencia cabecera/meta en errores y éxitos, limpieza del contexto entre peticiones API y web, reporte explícito que no se silencia y captura del detalle técnico de 500 con su ID. RuntimeException y QueryException se prueban con debug activo e inactivo, sin SQL, bindings, rutas ni secretos en el cuerpo. Las QueryException son sintéticas para provocar el error de transporte sin ejecutar SQL inválido; las pruebas de persistencia anteriores sí ejecutan SQL Server real.

Los logs de estas pruebas se capturan mediante el logger real de Laravel y un TestHandler de Monolog en memoria; no se simula Log con mocks ni se afirma haber provocado un 500 público. Las comprobaciones web verifican HTML nativo, JSON solicitado explícitamente y errores de transporte Livewire excluidos del sobre. El test previo de bootstrap sigue verificando la carga del script real de Livewire; no se realizó una interacción de navegador con un componente de negocio, porque no existe ninguno.

**Fallos intermedios corregidos:** Larastan detectó tres errores nuevos (comprobación redundante de tipo y acceso al traductor sin comprobar implementación concreta). La primera ejecución de las 29 pruebas nuevas tuvo seis fallos y un error: el callback del capturador de logs se enlazaba a LogManager y la expectativa del fallback de idioma era incorrecta. Se corrigieron el capturador y esa expectativa en el archivo nuevo; no se modificaron pruebas previas ni se desactivaron verificaciones. La suite completa final quedó sin fallos.

Una consulta auxiliar inicial a sqlsrv_testing desde el entorno de desarrollo falló por variables TEST_DB_* ausentes, antes de abrir conexión a esa base. Se repitió cargando expresamente .env.testing y devolvió fresapos_test con 0 tablas. No se cambiaron los perfiles ni se agregó fallback hacia fresapos.

**Pendiente/no ejecutado:** parser YAML y validación formal del esquema OpenAPI, al no existir un parser en el proyecto y estar prohibida una instalación solo para esa comprobación. El archivo manual no se presenta como validado automáticamente. No hubo pruebas Azure, despliegue cPanel, autenticación real, pruebas de carga ni reconfiguración de CORS.

## F. Compatibilidad

La implementación usa las APIs de Laravel 13 instalado, el contenedor y mecanismos nativos de enrutamiento, middleware, validación, limitación y manejo de excepciones. laravel-modules y sus 26 proveedores siguen cargando; las pruebas arquitectónicas pasan. Livewire 4.4.8 y el sitio web conservan su funcionamiento comprobado.

SQL Express AUDITORIA y Windows Authentication permanecen intactos. La infraestructura API no consulta tablas de negocio. La suite previa contra SQL Server continúa pasando, incluida la limpieza de sus tablas efímeras. ULID se conserva como string en API y nchar(26) en SQL según lo verificado en 1.2b; no se añadió otro mecanismo de persistencia.

**Azure SQL pendiente:** credenciales y red autorizadas, conexión real, CA/TLS, Encrypt=yes, TrustServerCertificate=no, collation, permisos, DNS y firewall. No se contactó Azure.

**cPanel pendiente:** PHP web 8.3, pdo_sqlsrv, sqlsrv y ODBC Driver 18 en el proceso web, compatibilidad del pool, red saliente y firewall. ionCube no sustituye los drivers ni prueba acceso SQL. Se mantienen [hosting.md](hosting.md) y [base-datos-azure.md](base-datos-azure.md). No se desplegó el proyecto en hosting.

## G. Preparación para autenticación, usuarios y permisos

Quedan listos /api/v1, nombres de rutas, inyección de ApiResponder, enum estable, traducciones, errores uniformes 401/403/422, registro de mapeos y correlación con logs. Los módulos Auth y Users continúan sin lógica. La siguiente etapa deberá decidir el guard, autenticación web/API, protección de rutas, Gates/Policies y reglas de permisos sin debilitar las fronteras modulares.

Sanctum es futuro y no está instalado. OpenAPI incluye solo un esquema bearer previsto, no aplicado. Quedan pendientes exposición de X-Request-Id en CORS si el cliente web la necesita, orígenes autorizados, política de límites por identidad, proxies y estrategia de caché del despliegue. Request ID no sustituye la idempotencia futura ni las convenciones financieras ya documentadas. No se inició 1.3.

## H. Integridad del entorno y de fresapos

No se modificaron .env, conexión local, autenticación de Windows, servicios SQL, permisos, firewall ni datos de fresapos. No se ejecutaron migraciones permanentes, migrate:fresh, db:wipe ni operaciones destructivas. La suite usa exclusivamente fresapos_test para DDL efímero, manteniendo sus salvaguardas.

Hashes SHA-256 idénticos antes y después:

- .env: `A8ED9F5D179949F9179F4466618AD26721D62A9E2353920DC8CF7CC3E0C97A2B`.
- composer.lock: `F3246EF3589272DE8FE9E6D0C9A0C66F0C29263A771768B6A77E9D33CD2FF37C`.

Se verificaron asimismo los hashes de todos los archivos de pruebas preexistentes contra la instantánea previa, sin diferencias. No hay Git disponible para un diff del repositorio; esta comprobación no se presenta como un git diff. No se hicieron commits ni pushes. El servidor local existente sigue disponible en http://127.0.0.1:8080. No se instalaron paquetes, Docker, Node, Vite, Redis, colas ni herramientas de arquitectura.

## I. Decisiones que requieren confirmación

No queda una decisión bloqueante para cerrar la implementación local de 1.2c. El enum y el registro de excepciones corresponden al plan confirmado. Las validaciones pendientes de YAML/OpenAPI, Azure y hosting quedan explícitas y no se declaran realizadas.

La autenticación, usuarios, roles, permisos y cualquier cambio de CORS pertenecen a una entrega posterior que necesitará su propio alcance y confirmación. El trabajo se detiene al cerrar 1.2c.
