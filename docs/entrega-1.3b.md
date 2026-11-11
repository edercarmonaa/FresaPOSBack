# Entrega 1.3b — Autenticación Web/API, PIN y sesiones

Fecha de validación local: 11 de noviembre de 2026. **Etapa completada localmente**, con pendientes de despliegue y controles avanzados expresamente identificados. No se inició 1.3c.

## A. Línea base

Se inspeccionaron los informes 1.2a–1.2c/1.3a, configuración, módulos, contratos, modelos, proveedores, rutas, migraciones y pruebas. Se reutilizaron los 13 módulos, TransactionManager y transporte JSON de Core, identidad/Policies/servicios de Users, Spatie y salvaguardas SQL. Sanctum no estaba instalado. No existía autenticación funcional.

Versiones reales: PHP 8.3.36, Laravel 13.42.0, Livewire 4.4.8, laravel-modules 13.3.0 y Spatie Permission 8.5.0. Windows, SQL Server Express .\AUDITORIA con autenticación Windows y aplicación en 127.0.0.1:8080.

Línea base ejecutada: **86 pruebas, 703 aserciones**, Pint correcto, Larastan nivel 5 correcto sobre 96 archivos; Composer validate --strict y check-platform-reqs correctos. git status informó que la carpeta no es un repositorio Git. No se inicializó uno y no hubo commits ni pushes.

La base operativa tenía nueve tablas, cuatro roles, 44 permisos y cero usuarios/historiales. No se eligieron credenciales operativas.

## B. Implementación y archivos

Única dependencia incorporada: **laravel/sanctum 4.4.0** (^4.4), compatible según su manifest y documentación oficial con las versiones instaladas; composer.json/lock actualizados sin actualizar otros paquetes. No se ejecutó install:api ni se publicaron migraciones genéricas incompatibles con ULID.

Archivos nuevos principales:

- Auth/Application: Authenticate, SessionRegistry y EmployeeAccess; Contracts/Authenticator y cuatro excepciones Domain.
- Auth/Infrastructure/Models: AccessToken, AccessSession y EmployeePin.
- Auth/Http: WebAuthenticationController, ApiAuthenticationController, Livewire/Login, InputMessages, SensitiveErrors y middleware EnsureWebAccess, EnsureApiAccess y NoStore.
- Auth/routes/web.php y routes/api/v1.php; cinco vistas Blade; diccionarios es/en de validación y errores; migración Auth incremental.
- Users/Contracts: IdentityAccess y UserAccessChanged; Application/Services/UserIdentityAccess e Infrastructure/Persistence/ActiveUserProvider.
- config/auth_access.php y config/sanctum.php.
- tests/Support/AuthSqlServerTestCase.php; tests/Integration/AuthenticationTest.php y AdminBootstrapAuthenticationTest.php.
- docs/autenticacion.md y este informe.

Archivos modificados: proveedores Auth/Users, modelo User (HasApiTokens nativo), ManageUsers (evento tras commit), BootstrapAdministrator y comando ProvisionAdministrator, config/auth.php, config/module-dependencies.php, bootstrap/app.php, catálogo ApiCode y traducciones Core. También README, CHANGELOG, OpenAPI y guías de arquitectura, API, persistencia, pruebas y permisos.

Solo dos archivos de pruebas anteriores cambiaron: ModuleRegistrationTest exige la migración/controladores reales de Auth, y ApiConventionsTest exige las cinco rutas API actuales. No se eliminaron casos, salvaguardas ni se redujo Larastan. Los demás archivos de pruebas previos conservan sus hashes.

## C. Autenticación Web

GET /login presenta Blade/Livewire con username y contraseña, CSRF y mensajes propios en español. También existe POST /login de respaldo. Se rechazan correo como username, usuarios inactivos/eliminados y credenciales incorrectas sin distinguir públicamente el motivo.

El proveedor activo, el registro servidor y los permisos se comprueban en cada acceso privado. El login regenera/destruye el ID anterior y renueva CSRF. Logout revoca el acceso, invalida la sesión y renueva CSRF. Account muestra la identidad vigente y controles mínimos; admin exige users.view en servidor.

El timeout Web predeterminado es 30 minutos de inactividad, parametrizable por AUTH_WEB_IDLE_MINUTES. Se aplica en memoria conservando .env y el driver file/cifrado existentes. La validación se realiza en servidor; pestañas comparten sesión y fallos visuales de Livewire no provocan logout.

Se reutiliza users:provision-admin, interactivo, sin argumentos de contraseña, entrada oculta y sin fallback visible. Comprueba catálogo protegido y que nunca haya existido un usuario. Queda disponible para ejecución manual; **no se ejecutó contra fresapos**. La primera cuenta debe cambiar su contraseña antes del acceso completo, reutilizando la política e historial de las tres últimas claves.

## D. Autenticación API

Sanctum usa bearer exclusivamente; una cookie Web no autentica API. No se habilitaron SPA stateful, CSRF auxiliar ni nuevas reglas CORS.

| Ruta | Resultado |
| --- | --- |
| POST /api/v1/auth/login | Emite token una sola vez tras credenciales válidas. |
| GET /api/v1/auth/me | Devuelve snapshot propio con roles/permisos efectivos. |
| POST /api/v1/auth/logout | Revoca únicamente el token actual. |
| POST /api/v1/auth/password | Completa el cambio obligatorio, revoca accesos anteriores y emite token nuevo. |

El endpoint adicional de contraseña permite completar el flujo obligatorio sin reset administrativo. Tokens ULID nativos, secreto generado por Sanctum, SHA-256 almacenado y expiración fija predeterminada de ocho horas. Sin refresh tokens; la actividad no extiende su vigencia. La capacidad técnica del token no sustituye permisos de Spatie.

Los sobres JSON, HTTP 401/403, X-Request-Id y meta.request_id de 1.2c se conservan. Se incorporaron INVALID_CREDENTIALS, SESSION_EXPIRED, PIN_INVALID y PASSWORD_CHANGE_REQUIRED; véase la tabla de casos en autenticacion.md. No se expone un login API por PIN.

## E. PIN

PIN numérico de 4–6 dígitos (conserva ceros iniciales), almacenado con Hash nativo y oculto en modelos/respuestas. Registro, cambio y deshabilitación propios exigen sesión vigente y reautenticación con contraseña. La verificación forma parte del servicio limitado de cambio de empleado, no es una credencial universal.

## F. Cambio rápido

Se autoriza un contexto de terminal Web con cash.open y contraseña; no se abre caja ni se aprueba un dispositivo. El servidor conserva el propietario, sin tomar esa identidad del cliente. El entrante debe estar activo, sin cambio obligatorio pendiente, tener PIN correcto y sales.create o cash.open. Se rechazan identidades con capacidades administrativas users.* o roles.*.

Se revoca el contexto anterior, autentica al nuevo empleado en el guard, regenera sesión/CSRF y crea un acceso atribuido a esa identidad con método pin. No se heredan permisos ni se transfieren tokens API. La pantalla mínima identifica al empleado activo. Las futuras operaciones deberán tomar el actor del servidor; la auditoría de negocio y la interfaz final POS siguen fuera de alcance.

## G. Base de datos

Migración incremental: Modules/Auth/database/migrations/2026_11_11_000002_create_auth_access_tables.php. Aplicada realmente con:

```powershell
.\scripts\local.cmd php artisan migrate --path=Modules/Auth/database/migrations --no-interaction
```

Resultado: migración [2] Ran. Crea tres tablas: personal_access_tokens, auth_access_sessions y auth_employee_pins. ULID nchar(26), FK reales NO ACTION hacia users, CHECK de tipo/canal e índices únicos filtrados para referencias opcionales. La FK de acceso a token usa SET NULL para conservar metadatos al revocarlo. No modifica destructivamente Users. El down está restringido a bases _test; no se ejecutó una reversión operativa.

Comprobación posterior real: fresapos conserva cuatro roles, sus IDs/etiquetas/asignaciones idénticos y 44 permisos; cero usuarios, historiales, tokens, accesos y PIN. Tiene doce tablas (tres nuevas). fresapos_test terminó con **cero tablas de usuario**.

La comparación SHA-256 confirma .env, config/database.php y config/session.php intactos. No hubo migrate:fresh, db:wipe, SQLite ni cambio de autenticación Windows. La compatibilidad de esquema se probó en Express; Azure SQL sigue pendiente de validación real.

## H. Seguridad y límites

Cookies HttpOnly, SameSite=Lax y Secure fuera de local/testing; las rutas/servicios de credenciales rechazan HTTP fuera de local/testing. CSRF Web, regeneración de sesión y consultas parametrizadas. Cambiar contraseña revoca accesos anteriores; cambios de estado Users notifican después del commit. La autorización se evalúa en servidor con los cuatro roles y 44 permisos existentes.

El registro guarda metadatos y SHA-256 del ID Web, sin duplicar payloads. Los listados propios no exponen referencias reutilizables. Cierre ajeno requiere users.status y actor habilitado. Límites iniciales: cinco sesiones Web y cinco API por usuario, parametrizables; login y reautenticación limitados, PIN limitado por username global. No equivalen todavía a bloqueo persistente ni garantía distribuida con caché file.

Auth habilita zend.exception_ignore_args en proceso para excluir secretos de trazas, sin cambiar php.ini. Errores API conservan su saneamiento; SensitiveErrors evita reflejar cuerpos/credenciales en errores 5xx de las pantallas Auth y Livewire auth.login, incluso con debug activo. Las demás vistas/componentes conservan su manejo previo. Las pruebas provocan errores SQL reales solo en fresapos_test. Los logs conservan detalle técnico, requieren acceso/retención restringidos y no deben recibir secretos en mensajes deliberados.

## I. Pruebas y resultados reales

Comandos ejecutados desde PowerShell con el lanzador local:

| Comando | Resultado final |
| --- | --- |
| .\scripts\local.cmd composer test | **111 pruebas, 1016 aserciones, satisfactorias** (64,530 s). |
| .\scripts\local.cmd composer lint | Pint passed. |
| .\scripts\local.cmd composer analyse | Larastan nivel 5, 130 archivos, sin errores. |
| .\scripts\local.cmd composer validate --strict | composer.json válido. |
| .\scripts\local.cmd composer check-platform-reqs | Todos los requisitos satisfechos, incluido sqlsrv/pdo_sqlsrv. |
| .\scripts\local.cmd php artisan migrate:status | Users [1] y Auth [2] Ran. |
| .\scripts\local.cmd php artisan route:list --except-vendor | 18 rutas propias; cinco API (ping + cuatro Auth). |

Las 25 pruebas adicionales incluyen aprovisionamiento y preguntas ocultas, login/logout Web/API, fijación de sesión, CSRF, timeout, usuarios inactivos/eliminados, almacenamiento/expiración/revocación de tokens, HTTP 401/403, contrato JSON, permisos dinámicos, cambio obligatorio e historial, PIN, cambio de empleado, aislamiento administrativo, acceso horizontal, límites, protocolo Livewire real, FK/ULID y secretos en respuestas/logs.

HTTP real en 127.0.0.1:8080: /, /up, /api/v1/ping y /login devolvieron 200; /account y /admin redirigieron a /login (302); /api/v1/auth/me sin token devolvió 401. También se comprobó JS Livewire 200, formulario con CSRF y POST login vacío 422 con mensajes en español. Los flujos positivos con credenciales se ejecutaron exclusivamente contra fresapos_test; no hubo login operativo positivo por ausencia deliberada de cuentas.

Durante el desarrollo hubo fallos corregidos: comparación de precisión temporal SQL, helper Livewire sin sesión y simulación de entrada secreta de Symfony en Windows. Se corrigieron las pruebas para usar el protocolo real/control de entrada, sin retirar middleware ni permitir claves visibles. Larastan detectó tipos y env fuera de config; se corrigieron sin baseline ni supresiones. Ninguna prueba final quedó fallida u omitida silenciosamente.

No ejecutado: consola física con credenciales operativas, Azure SQL, cPanel/ionCube/ODBC/firewall/HTTPS de hosting y validación formal YAML/OpenAPI (sin parser instalado). Estas ausencias no se presentan como comprobadas.

## J. Pendientes y continuidad

Para 1.3c: bloqueo/intentos y auditoría avanzados, política detallada e interfaz administrativa de revocación remota, refinamiento de contextos de terminal, retención/limpieza de accesos y tratamiento operativo de fallos abruptos. No se implementó negocio, Android ni aprobación de Devices.

Antes del hosting: verificar PHP web 8.3, pdo_sqlsrv/sqlsrv, ODBC, compatibilidad ionCube, TLS/proxy confiable, firewall/permisos Azure, sesiones/caché compartidas si hay varias instancias y protección de logs. No se conectó a servicios sin credenciales autorizadas.

Queda manualmente pendiente elegir credenciales privadas y ejecutar users:provision-admin. No hay decisiones de arquitectura bloqueantes ni aprobaciones pendientes. Se detiene el trabajo en 1.3b, sin commits ni pushes.

