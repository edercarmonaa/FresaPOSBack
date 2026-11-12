# FresaPOSBack — Validación y aceptación definitiva de 1.3c

Fecha: **12 de noviembre de 2026**, America/Mexico_City.

**ETAPA 1.3c: ACEPTADA en el entorno local Windows/SQL Server Express.** Este informe sustituye el estado pendiente de ejecución anterior. No se avanzó a 1.4 ni se realizaron commits o pushes.

## A. Entorno de validación

Raíz: C:\Repos\FresaPOSBack. Windows 11 Home Single Language, versión 10.0.26200; PHP CLI 8.3.36 NTS x64 y Composer 2.9.2 mediante scripts/local.cmd. SQL Server Express 17.0.1155.3, instancia .\AUDITORIA, Windows Authentication; drivers sqlsrv/pdo_sqlsrv 5.13.3. Se verificaron extensiones y requisitos de plataforma sin reinstalar herramientas ni dependencias.

Dependencias reales: Laravel 13.42.0, Livewire 4.4.8, Sanctum 4.4.0, laravel-modules 13.3.0, Spatie Permission 8.5.0, PHPUnit 12.5.43, Pint 1.32.2 y Larastan 3.14.0, nivel 5. No se cambió composer.json ni composer.lock.

## B. Línea base

Git estaba limpio sobre bac3444, implementación existente de 1.3c. Se inspeccionaron informes previos, Auth/Users/Core/Audit, contratos, proveedores, modelos, rutas, middleware, migraciones, políticas, configuración y pruebas. La referencia histórica de 1.3b era 111 pruebas y 1016 aserciones satisfactorias; no se confundió esa cifra con una ejecución actual.

Se identificaron **22 pruebas nuevas**: 21 métodos en AdvancedSecurityTest y uno en SecurityDiagnosticsTest. Las modificaciones existentes de 1.3c ya incorporaban bloqueos, gestión API/Livewire de sesiones, coordinación SQL y auditoría.

Estado inicial real: fresapos con doce tablas, un usuario administrador activo, un historial de contraseña, cuatro roles, 44 permisos, 81 asignaciones rol-permiso y una asignación usuario-rol. Cero tokens, registros de acceso y PIN. Users y Auth estaban migrados; Audit pendiente. fresapos_test estaba vacía. No se mostraron credenciales ni hashes del administrador.

Primera ejecución: **133 pruebas, 1216 aserciones, dos errores y un fallo**, en 270,603 s. Pint señaló cuatro archivos. Larastan detectó dos errores. Los hallazgos y las correcciones se detallan en J.

## C. Verificaciones ejecutadas

Comandos ejecutados desde PowerShell en la raíz:

```powershell
.\scripts\local.cmd php -v
.\scripts\local.cmd php -m
.\scripts\local.cmd composer --version
.\scripts\local.cmd composer show --direct --format=json
.\scripts\local.cmd composer validate --strict
.\scripts\local.cmd composer check-platform-reqs
.\scripts\local.cmd composer test
.\scripts\local.cmd composer lint
.\scripts\local.cmd composer analyse
.\scripts\local.cmd php artisan --version
.\scripts\local.cmd php artisan route:list --except-vendor
.\scripts\local.cmd php artisan migrate:status
```

Se inspeccionaron también git status, historial, diff, diff --check, procesos y puerto 8080. Las consultas SQL verificaron DB_NAME(), servicio Express, tablas, metadatos de esquema, catálogo de migraciones y huellas privadas de los datos existentes. Las comprobaciones HTTP usaron HttpClient sin enviar credenciales.

Los logs/evidencias locales de esta validación se conservan en .tools/acceptance-1.3c-*, directorio ignorado por Git. Las evidencias no se presentan como sustituto de los comandos reproducibles ni se incorporan secretos al informe.

## D. Pruebas

| Ejecución | Resultado real |
| --- | --- |
| Primera suite sobre implementación recibida | 133 pruebas, 1216 aserciones; dos errores y un fallo. |
| Regresión PIN antes de corregir producto | Un fallo reproducido: acceso Web 200 tras perder permisos operativos. |
| Regresión PIN corregida | Una prueba, ocho aserciones, satisfactoria. |
| Migración Audit aislada, comprobación final | Una prueba, 18 aserciones, satisfactoria. |
| Suite completa tras correcciones | **135 pruebas, 1256 aserciones**, satisfactoria; 124,670 s. |
| Suite completa posterior a migración operativa | **135 pruebas, 1256 aserciones**, satisfactoria; 125,940 s, 78 MB. |

La ejecución final no tuvo errores, fallos, pruebas omitidas ni advertencias PHPUnit. Se conservaron los 85 métodos históricos de prueba, comprobados contra 65a0e15; sus datasets explican los 111 escenarios originales. Se ejecutaron las 22 pruebas de 1.3c y dos regresiones adicionales de aceptación.

La prueba nueva de migración verifica nchar(26) mediante sus 52 bytes físicos, dos FK NO ACTION a users, índices, rechazo SQL 547 de referencias/resultados/clientes inválidos, inserción UTC y recuperación con un modelo Eloquent de prueba. Se ejecutó el up real de la migración en las fixtures aisladas; no se creó un modelo productivo innecesario.

La primera versión de esa comprobación usó dos expectativas incorrectas del helper: longitud expresada en bytes y texto no action con espacio. Se corrigieron contrastando sys.columns y los metadatos del driver, sin cambiar el esquema ni debilitar las restricciones.

Una invocación focalizada inicial con un filtro que contenía una tubería fue interpretada por el lanzador CMD y terminó antes de ejecutar pruebas. Se repitió con filtros individuales; no se contó como cobertura. La prueba negativa de salvaguarda descrita en F produce un error intencional y se distingue de la suite final satisfactoria.

## E. Calidad

- Pint final: **passed**. Se aplicó únicamente el formato señalado por la herramienta.
- Larastan final: **138 archivos, nivel 5, sin errores**. No hay nuevas supresiones, baseline ni reducción del nivel.
- Composer validate --strict: **válido**.
- Composer check-platform-reqs: **todos los requisitos satisfechos**, incluidos ambos drivers SQL Server.
- git diff --check: correcto.

La nota informativa de PHPStan en stderr aparece como NativeCommandError al capturar salida con Windows PowerShell 5.1; no se trató como fallo de análisis cuando el proceso terminó con salida 0 y No errors. Los errores reales iniciales sí se registraron y corrigieron.

## F. Base de datos y migración

La configuración de PHPUnit fuerza testing/sqlsrv_testing y usa .env.testing independiente. SqlServerTestCase retira las demás conexiones solo en memoria. TestDatabaseGuard valida nombre propio _test, driver sqlsrv, host explícito y DB_NAME() efectivo antes de DDL. Se revisaron estas reglas antes de la primera integración.

Se ejecutó además una prueba negativa real: TEST_DB_DATABASE=fresapos únicamente en el entorno del proceso, con el caso de conexión de PersistenceTest. Abortó con RuntimeException y salida 2 **antes de abrir la conexión o ejecutar DDL**, como exige assertConfigured. La variable se restauró en finally y la suite completa posterior usó fresapos_test. No se cambió ningún archivo de entorno para forzarla.

Migración revisada: Modules/Audit/database/migrations/2026_11_11_000003_create_security_events.php. Crea únicamente audit_security_events: PK ULID nchar(26); tipo de evento; actor/afectado y referencia administrativa opcionales; resultado, cliente, correlación y fecha UTC. Incluye FK NO ACTION, índices y CHECK de resultado/cliente. No altera Users, contraseñas, permisos, tokens ni registros previos. Su down rechaza bases que no terminan en _test. No se ejecutó rollback operativo.

Tras las pruebas aisladas, calidad correcta y respaldo verificado se ejecutó:

```powershell
.\scripts\local.cmd php artisan migrate --path=Modules/Audit/database/migrations/2026_11_11_000003_create_security_events.php --no-interaction
.\scripts\local.cmd php artisan migrate:status
```

Resultado real: **Audit [3] Ran**, junto a Users [1] y Auth [2]. fresapos tiene trece tablas; auditoría consultable y vacía tras las comprobaciones sin credenciales. La suite posterior volvió a probar inserción/consulta/restricciones en fresapos_test, que terminó con **cero tablas de usuario**.

No hubo migrate:fresh, db:wipe, migrate:reset, migrate:refresh ni SQLite. Las fixtures retiran únicamente sus esquemas tras comprobar la base; no se ejecutaron suites SQL simultáneas sobre fresapos_test.

## G. Respaldo operativo

Antes de migrar se obtuvo InstanceDefaultBackupPath y se confirmó que la carpeta estaba fuera del repositorio y que SQL Server podía acceder al directorio padre. Se eligió un nombre único con fecha/hora UTC y sufijo aleatorio; xp_fileexist confirmó que el archivo no existía.

Respaldo creado: **fresapos_pre_1_3c_20261112_161205Z_ff84278f.bak**. Conjunto de respaldo de 5 718 016 bytes, finalizado a las 10:12:05 locales; verificación concluida a las 16:12:05 UTC. Se conserva en la carpeta predeterminada protegida de Backup de la instancia, fuera de Git; no se sobrescribieron ni borraron respaldos anteriores.

Procedimiento ejecutado mediante PDO SQL Server, manteniendo Windows Authentication:

```sql
-- @path: archivo único en la carpeta de respaldo accesible por SQL Server.
-- @name: identificador fechado del conjunto.
BACKUP DATABASE [fresapos] TO DISK = @path
WITH COPY_ONLY, CHECKSUM, NOINIT, NOFORMAT, NAME = @name, STOP_ON_ERROR;
RESTORE VERIFYONLY FROM DISK = @path WITH CHECKSUM, STOP_ON_ERROR;
```

Se drenaron los resultados del driver y se verificaron: archivo existente con xp_fileexist después de BACKUP, lectura mediante RESTORE VERIFYONLY y registro msdb.backupset/backupmediafamily para fresapos, is_copy_only=1, has_backup_checksums=1 y tamaño positivo. La ruta exacta se conserva en la evidencia privada .tools/acceptance-1.3c-backup.json, sin publicarla aquí.

Una comprobación adicional Get-Item de PowerShell fue denegada por los permisos de la carpeta de SQL Server. No se alteraron ACL: la comprobación efectiva de existencia y lectura se realizó con el servicio SQL y terminó satisfactoriamente. **No se afirma haber restaurado una copia ni validado toda su estructura mediante una restauración**; VERIFYONLY verifica legibilidad/conjunto/checksums. Referencias: [BACKUP oficial](https://learn.microsoft.com/en-us/sql/t-sql/statements/backup-transact-sql?view=sql-server-ver17) y [VERIFYONLY oficial](https://learn.microsoft.com/en-us/sql/t-sql/statements/restore-statements-verifyonly-transact-sql?view=sql-server-ver17).

## H. Seguridad comprobada

| Control | Evidencia y comportamiento |
| --- | --- |
| Contraseña y bloqueos | Quinto fallo, bloqueo completo de 300 segundos desde ese fallo, 429/Retry-After, recuperación y limpieza de contadores; aislamiento por identidad/origen sin enumerar cuentas. |
| PIN | Hash, cambio/deshabilitación, bloqueo recuperable entre navegadores y contexto autorizado; no autentica API ni habilita administración. La pérdida de permisos operativos invalida el acceso vigente. |
| Sesiones | Revocación individual Web/API y masiva, rechazo en siguiente petición, idempotencia, cierre de otros accesos y aislamiento horizontal. |
| Vigencia/capacidad | Inactividad Web de 30 minutos, vencimiento fijo API y liberación de capacidad al expirar/revocar; límites por canal conservados sin expulsión silenciosa. |
| Usuarios/permisos | Desactivación/reactivación/borrado lógico, cambio de clave por contrato y permisos efectivos actuales Web/API; reactivar no recupera accesos anteriores. |
| Consistencia | Misma fila de coordinación de Users para identidad/emisión/revocación; dos conexiones SQL prueban exclusión incluso sin sesiones. Rollback real de estado/revocación ante fallo de auditoría. |
| Auditoría | Tipos/resultados permitidos, actor y sujeto cuando corresponden, referencias ULID, UTC, cliente y request_id; sin payload libre, contraseñas, PIN, tokens, hashes, IP completa, consultas ni conexiones. |
| Diagnóstico | QueryException conserva clase, SQLSTATE, código de driver y ubicación, excluyendo SQL/bindings/mensajes del driver. Respuestas y logs probados sin secretos; saneamiento Auth/Livewire conservado. |

Auth depende de los contratos Users/Core; Users y Audit dependen de Core. SecurityEvent no crea dependencia inversa ni ciclo. La auditoría sensible es síncrona dentro de la transacción, no un evento secundario que intente revertir una operación ya confirmada.

Límites explícitos: caché file y locks cubren este servidor; varios nodos requieren almacén compartido/proxies confiables. La coordinación SQL no convierte cookies/caché en una transacción distribuida. Una revocación no cancela peticiones previamente autorizadas. Expiraciones/desbloqueos se registran cuando se detectan; no hay barrido universal de tokens/sesiones abandonados. Los listados muestran hasta 50 registros; la revocación masiva no usa ese límite.

Retención mínima declarada de auditoría: 365 días, configurable; sin purga automática. Alertas, exportación, archivo e inmutabilidad deben tratarse en la operación/despliegue. No se presenta esta validación como certificación OWASP ni prueba de carga distribuida.

## I. Web, API y Livewire

No había un proceso escuchando en 8080; se inició una única instancia de artisan serve en 127.0.0.1:8080 y se dejó disponible. route:list --except-vendor registra **23 rutas propias**, diez de ellas API (ping, cuatro de autenticación y cinco de sesiones).

Comprobaciones HTTP reales, con 18 solicitudes:

- GET /, /up, /api/v1/ping, /login y JavaScript local de Livewire: **200**.
- GET /account, /admin y /auth/password sin identidad: **302 a login**.
- Auth me/logout/password y las cinco operaciones de sesiones sin token: **401**.
- POST /api/v1/auth/login con cuerpo vacío: **422**.
- Ruta técnica exclusiva de pruebas ausente en servidor: **404**.
- Sobres API y X-Request-Id/meta.request_id consistentes; formulario con CSRF, snapshot Livewire, username y password; sin diagnósticos sensibles.

Los flujos positivos/negativos 200/401/403/422/429 con credenciales se comprobaron exclusivamente en fresapos_test mediante las rutas reales, SQL Server, cookies y tokens Sanctum. Incluyen login/logout, revocación, administración autorizada/denegada y protocolo HTTP Livewire tras revocación remota. No se usó el administrador operativo para login, bloqueo, cambio de clave o desactivación.

## J. Cambios correctivos

| Archivo | Hallazgo y solución |
| --- | --- |
| tests/Feature/ApiConventionsTest.php | Lista antigua de cinco rutas y acceso al objeto SQL eliminado del log. Se exigen las diez rutas reales y diagnóstico seguro sin objeto/mensaje/bindings; se preservan y amplían las aserciones de privacidad. |
| Modules/Auth/app/Application/SessionRegistry.php | Argumento no booleano en abort_unless: comparación explícita con null. Se añade rechazo de sesión PIN cuando ya no hay sales.create ni cash.open, aun con propietario válido. |
| Modules/Auth/app/Application/EmployeeAccess.php | El cambio de empleado solo revisaba permisos antes de adquirir el lock. Se reconsulta identidad/propietario y verifica el alcance actual dentro de la coordinación, devolviendo el snapshot actualizado. Se eliminó una condición redundante detectada por Larastan, sin suprimir el diagnóstico. |
| config/audit_security.php y Modules/Audit/config/config.php | env en configuración modular señalado por Larastan. La lectura se centraliza en config raíz y el módulo conserva su clave pública de retención. |
| tests/Integration/AdvancedSecurityTest.php | Dos regresiones nuevas: retirada de permisos PIN y esquema/UTC/FK/CHECK/índices/Eloquent de Audit. Ningún caso anterior eliminado. |
| ApiAuthenticationController, AuthServiceProvider, SecurityDiagnosticsTest y AdvancedSecurityTest | Ajustes de formato/imports exigidos por Pint. |
| Documentación | Informe definitivo y actualización de referencias de etapa/validación y permisos PIN. |

No se modificó la migración recibida: superó las pruebas SQL. No se alteraron contratos públicos, matriz de permisos ni arquitectura general. Se consultó la [explicación oficial de tipos de PHPStan](https://phpstan.org/error-identifiers/argument.type) y se inspeccionó la regla instalada de Larastan; no se añadieron exclusiones.

## K. Integridad

La comparación privada por tabla de todas las filas previas confirma **once tablas existentes idénticas**, incluyendo usuarios, contraseña/historial, roles, permisos y asignaciones; se excluye de esa comparación únicamente migrations, que añade la fila Audit prevista. La nueva tabla se mantiene vacía; no se generaron eventos ficticios operativos.

Resultado: un administrador activo, un usuario, un historial, cuatro roles y 44 permisos conservados. No se ejecutaron aprovisionamiento, seeders, cambios de contraseña/estado del administrador ni eliminación de usuarios operativos.

Huellas inicial/final idénticas para ocho archivos protegidos: .env, .env.testing, config/database.php, config/session.php, composer.json, composer.lock, phpstan.neon y phpunit.xml. El driver de sesión y Windows Authentication se conservan. No hubo instalación ni cambio de motor.

Git conserva HEAD bac3444; los cambios correctivos/documentales quedan sin commit. Las evidencias privadas y respaldos no se añaden al control de versiones.

## L. Pendientes

**Ningún bloqueo crítico local de 1.3c.** Comprobaciones que siguen fuera de lo verificado:

- Azure SQL y cPanel: drivers/ODBC/PHP web/ionCube, firewall, TLS, proxy y configuración según topología; no hay credenciales/despliegue autorizados para probarlos.
- Validación formal contra el esquema OpenAPI: no hay validador/parser instalado en este entorno Windows; no se añadió una dependencia solo para documentar aceptación. La sintaxis/referencias comprobadas con Ruby en la implementación anterior son evidencia histórica, no una ejecución repetida aquí.
- Restauración de ensayo del respaldo a otro destino, carga simultánea de clientes completos y controles operativos distribuidos: no ejecutados; no se presentan como comprobados.

Estos límites no sustituyen ni invalidan las pruebas SQL, respaldo verificable y controles funcionales ejecutados. No se abrió la etapa 1.4 para resolverlos.

## M. Estado final

**ETAPA 1.3c: ACEPTADA.** Las comprobaciones críticas locales se ejecutaron satisfactoriamente, el respaldo fue creado/verificado antes del cambio, Audit quedó migrado, la suite final pasó y el administrador/datos operativos permanecen intactos. Se detiene esta entrega sin commits ni pushes.
