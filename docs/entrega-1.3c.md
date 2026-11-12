# FresaPOSBack — Bloque 02, etapa 1.3c

Fecha de trabajo: 11 de noviembre de 2026, America/Mexico_City.

**Estado: implementación local y documentación entregadas; aceptación pendiente por falta del entorno de ejecución.** No se afirma que la etapa haya superado PHPUnit, Pint, Larastan, integración SQL Server ni comprobaciones HTTP. No se avanzó a otra etapa.

## A. Línea base e inspección

Git estaba limpio. Se revisaron los informes 1.3a/1.3b, Auth, Users, Core, Audit, servicios, rutas Web/API, PIN, middleware, modelos Sanctum, registro de accesos, Policies, permisos, excepciones, migraciones y salvaguardas de pruebas.

La referencia histórica de 1.3b es **111 pruebas y 1,016 aserciones**, con Pint, Larastan nivel 5 y Composer correctos. Es evidencia del informe anterior, **no una ejecución reproducida aquí**. Hay 85 métodos de prueba previos en los archivos rastreados; los datasets explican que ese número no equivalga al total de ejecuciones PHPUnit.

Diferencias del entorno: esta copia está en macOS, carece de `php`, `composer`, `vendor` y `.env`. No contiene la instancia Windows `.\AUDITORIA` ni una conexión operativa verificable. Los comandos de línea base y finales devolvieron salida 127 (ejecutable inexistente). No se instalaron herramientas ni se cambió la plataforma del proyecto para ocultar esta diferencia.

Ya existían autenticación, timebox/hashes, proveedor de usuarios activos, sesiones con ULID propio, revocación, límites de cinco sesiones por canal, historial de tres contraseñas, CSRF/regeneración, middleware de vigencia y consulta de permisos actuales. Audit solo tenía el esqueleto modular. Los limitadores anteriores eran de un minuto; la invalidación de estado se despachaba después del commit.

## B. Implementación y archivos

Se extendieron los componentes existentes; no hay otro autenticador, gestor de sesiones ni gestor transaccional.

| Componente | Cambio |
| --- | --- |
| `Modules/Auth/app/Application/AttemptGuard.php` | RateLimiter, locks de caché, fallos consecutivos y bloqueos recuperables. |
| `Authenticate.php`, `EmployeeAccess.php` en la misma carpeta | Integración de bloqueos, revalidación bajo coordinación transaccional, auditoría y PIN de terminal. |
| `SessionRegistry.php` | Consulta/revocación autorizada, cierres masivos, expiración detectable y coordinación de emisión/revocación. |
| `Modules/Auth/app/Policies/SessionPolicy.php` | Actor activo, cambio obligatorio completado y alcance propio/administrativo. |
| `Modules/Auth/app/Infrastructure/Models/AccessSession.php` | Relación Sanctum para etiqueta de cliente disponible, sin serializar el token. |
| `Modules/Auth/app/Http/Controllers/ApiAuthenticationController.php` | Cinco operaciones de sesiones adicionales en el controlador existente. |
| `Modules/Auth/routes/api/v1.php` | Rutas de administración con Sanctum, middleware, validación ULID y permisos. |
| `Modules/Auth/app/Http/Livewire/Sessions.php` | Consulta y revocación, autorización en servicio y selección protegida mediante Locked. |
| `Modules/Auth/resources/views/livewire-sessions.blade.php`, `account.blade.php`, `admin.blade.php` | Interfaz mínima integrada en el diseño existente. |
| `Modules/Auth/app/Providers/AuthServiceProvider.php` | Registro Livewire y consumo síncrono de cambios de identidad. |
| `Modules/Auth/app/Http/SensitiveErrors.php` | Incluye auth.sessions en saneamiento de errores sensibles. |
| `Modules/Users/app/Contracts/IdentityAccess.php`, `UserAccessChanged.php` | Expone coordinación existente; evento incorpora actor y motivo. |
| `Modules/Users/app/Application/Services/UserIdentityAccess.php` | Delegación del lock al SecurityStore existente; cambio de clave invalida accesos y audita dentro de la transacción. |
| `ManageUsers.php` en la misma carpeta | Invalidación síncrona de estado/borrado y auditoría de estado, roles y permisos. |
| `Modules/Users/app/Application/BootstrapAdministrator.php` | Audita futuros aprovisionamientos autorizados; no se ejecutó el comando operativo. |
| `Modules/Core/app/Contracts/ExceptionDiagnostic.php`, `Modules/Core/app/Http/ApiExceptionHandler.php` | Diagnóstico SQL sin mensajes, consultas ni bindings potencialmente sensibles. |
| `Modules/Core/app/Contracts/SecurityEvent.php` | Contrato transversal, catálogo cerrado, resultado permitido y referencias exclusivamente ULID. |
| `Modules/Audit/app/Application/RecordSecurityEvent.php`, proveedor Audit | Listener síncrono con proyección explícita de campos, sin payload libre. |
| `Modules/Audit/database/migrations/2026_11_11_000003_create_security_events.php` | Única migración nueva. |
| `config/auth_access.php`, `Modules/Audit/config/config.php` | Política configurable de fallos/bloqueo y retención. |
| `tests/Integration/AdvancedSecurityTest.php`, `tests/Feature/SecurityDiagnosticsTest.php` | 22 métodos nuevos: 21 de integración y uno de diagnóstico sin base de datos. |
| Fixtures Auth/Users y pruebas de registro/autenticación | Incorporan Audit y adaptan la duración esperada del bloqueo; no eliminan métodos anteriores. |
| `docs/autenticacion.md`, `docs/api.md`, `docs/openapi.yaml` | Actualización de políticas y contratos. |

Se conserva la matriz modular: Auth consume contratos Users/Core; Users y Audit consumen Core. El contrato de evento evita dependencias de Users hacia Auth/Audit. Auth obtiene el lock mediante IdentityAccess, que delega al SecurityStore original; no importa modelos privados de Users ni implementa otro gestor de transacciones.

## C. Intentos, bloqueo y recuperación

Valores iniciales: `AUTH_FAILED_ATTEMPTS=5`, `AUTH_LOCK_SECONDS=300`. Son variables opcionales; no se modificó `.env`. El mismo límite rige contraseña, reautenticación y PIN, con claves independientes por mecanismo.

Se cuentan fallos consecutivos dentro de una ventana de 300 segundos. El quinto fallo crea un bloqueo de **300 segundos completos desde ese fallo**. Un éxito previo al bloqueo limpia el contador. Durante el bloqueo se rechazan también credenciales correctas, con HTTP 429 y Retry-After; al expirar se admite otra verificación y se registra el desbloqueo cuando se detecta. El marcador de desbloqueo se conserva hasta 24 horas; si no llega otra solicitud en ese plazo, no se reconstruye artificialmente ese evento.

Las claves contienen SHA-256 de identidad normalizada y origen IP; no se guardan username o IP en claro en el limitador ni en auditoría. Login Web/API comparten política. El PIN no depende del componente, cookies o ID Web: recargar la pantalla o iniciar otro navegador desde el mismo origen no elimina el bloqueo. Se comprueba el contexto de terminal en el servidor. El PIN no autentica API ni habilita administración Users/Roles; cada petición de una sesión PIN vuelve a comprobar su alcance y la autorización del propietario de terminal.

El alcance identidad/origen evita imponer un bloqueo global sobre una cuenta ajena desde otra IP. Continúa el limitador general API existente. Esto no impide ataques distribuidos ni sustituye la configuración de proxies confiables. Los locks nativos serializan comprobar/verificar/contar para una clave. El caché file existente es persistente en un servidor; varios nodos requieren caché compartido con locks. Borrar o perder ese caché reinicia los contadores. No existe bloqueo permanente de cuentas.

Los fallos y bloqueos se auditan sin usuario afectado cuando no se ha verificado identidad; así no se introduce una consulta diferencial ni se revela existencia de cuentas. Los mensajes Web/Livewire y API conservan el contrato seguro existente. La reautenticación de PIN también aplica el bloqueo; se conserva el throttle adicional de cambios de contraseña.

## D. Sesiones: consulta, límites y revocación

Se reutilizan `auth_access_sessions`, los tokens Sanctum y el driver Web existente. El ULID público identifica un **registro administrativo**, no autentica y no es el ID de cookie/token. No se devuelve session_hash, token_id, secreto, hash de token ni IP. Se muestra usuario, canal, etiqueta del token cuando todavía existe, fechas UTC, expiración y estado active/expired/revoked. Después de eliminar un token su etiqueta puede quedar indisponible.

El listado limita a 50 registros, priorizando no revocados y vigencia descendente; no es un historial paginado completo. Las revocaciones masivas operan sobre todos los registros, sin ese límite. No existe listado global de todos los usuarios.

Se conservan `AUTH_MAX_WEB_SESSIONS=5` y `AUTH_MAX_API_SESSIONS=5`: límites independientes por canal, no una nueva regla global de negocio. Revocados y expirados no consumen capacidad. Al alcanzarla se devuelve 429, conservando dispositivos existentes. La interfaz permite liberar capacidad explícitamente. No hay expulsión silenciosa.

La emisión, revocación y cambios de identidad usan la misma fila de coordinación SQL de Users mediante su contrato y TransactionManager de Core. Esto cubre también la primera emisión sin sesiones previas. Se vuelve a verificar la contraseña dentro de la coordinación de emisión para evitar emitir con una verificación anterior a un cambio de credenciales. El cambio de empleado comprueba de nuevo contexto y PIN bajo coordinación; la retirada del acceso anterior se revierte si falla la emisión.

Revocar Web marca el registro como revocado; no necesita conocer ni devolver el ID físico del driver. La siguiente petición protegida pierde acceso aunque sobreviva el payload físico. Revocar API marca el registro y elimina el token. Un registro revocado nunca se vuelve a marcar activo. Una petición que ya pasó la autorización puede estar en curso: esto no cancela ni deshace operaciones financieras confirmadas.

La revocación individual es idempotente sobre un registro existente; la solicitud administrativa repetida queda trazada. Para repetir una petición autenticada el actor debe conservar otro contexto válido. Un token que acaba de revocarse no puede autorizar su propia repetición.

## E. Acceso, credenciales y permisos

Consultar/cerrar accesos propios es una capacidad inherente al actor autenticado, activo y con cambio obligatorio completado. Administrar otros usuarios exige `users.status`, reutilizando el permiso que ya aplicaba a la revocación individual. SessionPolicy y los servicios verifican el alcance incluso fuera de HTTP; las rutas administrativas agregan `can:users.status`. No se añadieron permisos ni se ampliaron roles operativos.

Desactivar/eliminar mediante UserAdministration modifica el estado, revoca accesos/tokens, elimina el PIN e invalida terminales dependientes **dentro de la transacción original**. Reactivar no recupera sesiones anteriores. El proveedor y middleware siguen rechazando identidades inactivas/eliminadas en cada petición, incluso si tenían acceso emitido previamente.

IdentityAccess::changePassword revoca accesos anteriores centralmente, incluso fuera del controlador HTTP. El flujo HTTP Web crea otro contexto actual; API emite un nuevo token y elimina los anteriores. Se conserva el historial existente y el cambio obligatorio, sin restablecimientos administrativos.

Los cambios de roles/permisos conservan los servicios, locks y limpieza de PermissionRegistrar existentes. Se consulta autorización vigente en servidor; no se almacena un snapshot de permisos como autoridad en navegador/Android. Quitar permisos no requiere invalidar todos los dispositivos. Una sesión PIN pierde su contexto si adquiere capacidades administrativas de Users/Roles o su propietario deja de autorizar terminales.

Si la auditoría falla durante una modificación sensible de base de datos, se propaga el error y se revierte esa misma transacción. Ya no existe la ventana entre commit de desactivación y callback de revocación. La regeneración de cookie/CSRF y los contadores de caché no son transaccionales con SQL: ante un fallo la petición no informa éxito y la ausencia/vigencia del registro impide acceso posterior; puede ser necesario autenticarse nuevamente. Los errores se reportan mediante el manejo existente y no se silencian.

## F. Auditoría y retención

Audit persiste eventos de login, autenticación fallida, logout, bloqueo/desbloqueo detectable, expiración detectable, cambio de contraseña/PIN, revocación de sesión/token, desactivación/reactivación/borrado, roles, permisos y aprovisionamiento futuro autorizado.

Tabla `audit_security_events`: ULID, event_type, actor_id, subject_id, record_id administrativo opcional, result, client (web/api/console), request_id cuando existe y occurred_at UTC. La correlación API usa el ID de Core; Web genera uno por solicitud si falta. Cambios de rol/permisos conservan el ULID de rol como referencia. Revocaciones iniciadas por un actor registran su identidad.

El contrato rechaza tipos/resultados no previstos y referencias que no sean ULID. El listener inserta una lista cerrada; no recibe cuerpos, encabezados, contraseñas, PIN, hashes, cookies, tokens, secretos ni conexiones. No almacena IP completa. Los errores del registro no incluyen valores rechazados en sus mensajes. ExceptionDiagnostic sanea también los logs de QueryException de API y Auth: conserva tipo, SQLSTATE, código de driver y ubicación, excluyendo mensajes del driver, SQL y bindings que podrían contener hashes. Las excepciones siguen propagándose y las respuestas mantienen su contrato. No se construyó una API pública de auditoría ni un purgador.

Política inicial: retención mínima de eventos de seguridad durante **365 días**, configurable mediante AUDIT_SECURITY_RETENTION_DAYS. No hay borrado automático; pueden conservarse por más tiempo. Antes de eliminación autorizada, el responsable debe revisar retención aplicable, preservación por incidentes y archivo verificable. Esta configuración declara la política; no programa una eliminación al vencerla. No afecta ni elimina auditoría financiera. El despliegue deberá limitar permisos SQL sobre auditoría y controlar acceso a logs y respaldos.

La expiración Web se registra cuando el registro vencido llega a validación. Sanctum puede rechazar un token ya vencido antes de llegar al middleware de Auth; no se promete un evento de expiración para cada token ni sesiones que nunca vuelven a usarse. No se añadió un barrido automático ni se recorren tokens inválidos para reconstruir usuarios desde secretos.

## G. API y contratos

Prefijo `/api/v1/auth`. Todas las rutas de sesiones requieren Sanctum y auth.access.api; cambio obligatorio pendiente produce 403.

| Método | Ruta relativa | Resultado |
| --- | --- | --- |
| GET | /sessions | data.sessions propios. |
| DELETE | /sessions/{id} | Revoca un registro propio o ajeno autorizado. |
| DELETE | /sessions | Revoca todos los accesos propios; `{ "others": true }` conserva únicamente el contexto API actual, cerrando también Web. |
| GET | /users/{userId}/sessions | Lista del usuario; requiere users.status. |
| DELETE | /users/{userId}/sessions | Revoca todos los accesos del usuario; requiere users.status. No acepta others=true. |

Respuesta de revocación: sobre Core habitual con success=true, code=OPERATION_SUCCESS, data=null, message y meta.request_id; HTTP 200. Listado incluye id, user_id, channel, client_name, created_at, last_activity_at, expires_at, revoked_at y state. La cabecera X-Request-Id coincide con el identificador del sobre.

Sin token/contexto válido: 401. Sin autorización: 403. ULID mal formado en ruta o registro inexistente: 404. Cuerpo others inválido: 422. Bloqueo/exceso: 429; el bloqueo de autenticación incluye Retry-After. Se conserva la normalización JSON de Core. Los IDs no sustituyen autorización por propietario.

## H. Interfaz Web

`/account` integra auth.sessions para el actor actual. Un usuario con users.status puede consultar otro ULID desde el mismo componente; `/admin` conserva su requisito users.view y también integra la gestión. No hay rediseño del panel ni CRUD de usuarios.

La vista muestra identidad, canal/cliente disponible, fechas UTC y estado. Permite revocar uno, los demás propios o todos del usuario consultado. Las acciones destructivas presentan wire:confirm; una consulta valida ULID y autorización antes de fijar el usuario seleccionado en una propiedad Locked. El servidor vuelve a autorizar cada acción y muestra su resultado. El middleware Web persiste en actualizaciones Livewire, con CSRF, vigencia y usuario activo; ocultar controles no se considera autorización.

## I. Resultados reales y pruebas

| Comprobación ejecutada | Resultado |
| --- | --- |
| Git inicial | Limpio. |
| composer test, lint, analyse, validate, check-platform-reqs | No ejecutables: salida 127, composer inexistente. |
| php -v, artisan route:list y migrate:status | No ejecutables: salida 127, php inexistente. |
| PHPUnit/aserciones finales | **Sin resultado**; no se ejecutó ninguna prueba en este entorno. |
| SQL Server/Azure SQL | Sin conexión; migración y compatibilidad runtime pendientes. |
| /up, /api/v1/ping, login, interfaz Web/Livewire | Sin servidor de aplicación disponible; verificación funcional pendiente. |
| git diff --check | Correcto, sin errores de espacios. |
| Comparación de métodos previos contra HEAD | Se conservan los 85 métodos previos; no equivale a aprobar sus ejecuciones/datasets. |
| Nuevos casos declarados | 22 métodos: 21 en AdvancedSecurityTest y uno en SecurityDiagnosticsTest; no se presentan como aprobados. |
| Escaneo textual de imports | Sin nuevas violaciones en Core/Auth/Users/Audit; suite arquitectónica AST pendiente. |
| composer.json y composer.lock | JSON válido y bytes iguales a HEAD; no sustituye composer validate. |
| YAML OpenAPI mediante Ruby/Psych | Sintaxis válida, 8 paths, 102 referencias internas resueltas. No es validación formal OpenAPI. |
| Configuración SQL, sesiones, módulos y Larastan | Iguales a HEAD; nivel 5 conservado. |

Las pruebas nuevas cubren quinto fallo y duración completa, desbloqueo y limpieza del contador, separación por origen, PIN tras cambio de navegador, revocación remota Web, tokens y cierres por usuario, acceso horizontal, idempotencia, cierre de otros contextos, capacidad tras expiración/revocación, desactivación/reactivación, borrado lógico, cambio de clave a través del contrato, permisos vigentes Web/API, rollback ante fallo de auditoría, campos permitidos/ausencia de secretos, saneamiento de diagnósticos SQL, acciones Livewire autorizadas/denegadas y protocolo HTTP Livewire tras revocación.

La prueba de concurrencia utiliza dos conexiones SQL reales y timeout 1222 sobre el lock compartido, aun sin sesiones previas. Es una prueba de exclusión, no carga simultánea de clientes completos ni un benchmark. Usa TestDatabaseGuard antes de actuar y copia únicamente la configuración sqlsrv_testing. Las fixtures siguen exigiendo entorno testing y base propia _test, y desinstalan únicamente el esquema que ellas crearon. No ejecutar suites simultáneas contra la misma fresapos_test.

Únicos ajustes en pruebas previas: instalar/desinstalar el nuevo esquema Audit en fixtures de Auth/Users, registrar la migración adicional esperada y cambiar de 61 a 301 segundos el avance de tiempo del bloqueo PIN. No se eliminó ningún método ni se bajó Larastan.

### Verificación requerida en el entorno Windows existente

Con la configuración aislada de pruebas ya autorizada, ejecutar secuencialmente `composer test`, `composer lint`, `composer analyse`, `composer validate` y `composer check-platform-reqs` mediante scripts/local.cmd. Comprobar primero las salvaguardas y el estado de fresapos_test. Si Pint indica diferencias, aplicar formato y repetir los controles pertinentes. No usar SQLite para presentar compatibilidad SQL ni pruebas aprobadas.

Consultar migrate:status en el entorno existente. Tras verificar la integración, aplicar únicamente la migración incremental de Audit con el path `Modules/Audit/database/migrations/2026_11_11_000003_create_security_events.php`, antes de habilitar este código de escritura, pues la auditoría falla de forma cerrada si falta la tabla. No ejecutar el aprovisionamiento de administrador ni seeders operativos para esta entrega.

Finalmente comprobar rutas reales, /up, /api/v1/ping, login, JavaScript y acciones Livewire, revocación Web/API y estados 401/403 con identidades autorizadas. Documentar conteos/aserciones y resultados reales en este informe. No ejecutar operaciones de limpieza contra fresapos.

## J. Riesgos y revisión OWASP

| Riesgo revisado | Medida concreta y límite |
| --- | --- |
| Acceso horizontal/escalada | Actor del guard, SessionPolicy, users.status, autorización por servicio y selección Livewire Locked. Pendiente ejecución HTTP/SQL. |
| Autenticación/fuerza bruta | Fallos limitados por identidad/origen, ventana y bloqueo completos, hashes/timebox existentes. No resuelve ataques distribuidos ni proxy mal configurado. |
| Sesiones y tokens | Revocación central, comprobación por petición, límites deterministas, coordinación SQL y ningún ID autenticante en listados. Peticiones ya autorizadas pueden estar en curso. |
| Cambios de identidad | Invalidación síncrona con auditoría atómica; reactivación no restaura accesos. Deben usarse los contratos de Users; SQL manual está fuera del flujo soportado. |
| Criptografía/datos sensibles | Hashes y tokens nativos conservados; esquema y contrato de auditoría sin payload libre. TLS, acceso a SQL/logs y respaldos requieren revisión del despliegue. |
| Configuración | No se altera .env, drivers, HTTPS/CSRF ni nivel de análisis. Caché compartido y proxies confiables pendientes según topología. |
| Registro/monitoreo | Eventos de seguridad con correlación y fallos propagados. Alertas, exportación protegida, inmutabilidad SQL y archivo/retención operativa quedan pendientes. |
| Disponibilidad | Lock de seguridad existente compartido para consistencia; serializa emisiones y cambios sensibles. Medir contención y doble verificación de credenciales antes de ampliar carga. |

También quedan pendientes PHP 8.3/SQL Server Express reales, migración aplicada, aprobación de calidad, esquema OpenAPI formal, Azure SQL y hosting. No se simularon resultados ni se afirma protección completa frente a OWASP Top 10.

## K. Base de datos e integridad

La migración nueva crea únicamente audit_security_events con ULID nchar(26) mediante el soporte SQL Server existente, FK NO ACTION a users, índices por tipo/fecha/usuario y CHECK de resultado/cliente. No altera tablas operativas, usuarios, roles, permisos, historial, tokens ni datos financieros existentes. Su down destructivo está limitado a una base terminada en _test.

**La migración no se aplicó en esta sesión.** No se pudo inspeccionar qué migraciones están aplicadas en la instancia Windows ni sus sesiones activas. No se ejecutaron migrate:fresh, db:wipe, limpieza operativa, aprovisionamiento, resets, seeders, commits ni pushes.

Esta copia no tenía .env; no se creó ni modificó. config/database.php, config/session.php, composer.json, composer.lock, modules_statuses.json y phpstan.neon conservan exactamente los bytes de HEAD. No se cambió conexión SQL, Windows Authentication, hosting ni Docker.

No se abrió conexión con fresapos ni se consultaron credenciales del primer administrador. Por ausencia de acceso a esa instancia, no puede certificarse su estado actual; **ninguna acción de esta sesión afectó esa cuenta o contraseña**.

La etapa se detiene aquí. El impedimento para declararla completada es la validación ejecutable y operativa ausente, no una aprobación pendiente del usuario ni el inicio de otra funcionalidad.
