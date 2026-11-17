# Entrega 1.5d.1 — Fundamentos de Cash

17 de noviembre de 2026, America/Mexico_City. **ETAPA 1.5d.1: ACEPTADA LOCALMENTE**. Cash funcional y probado en SQL Server Express; apertura/agregado operativos de Sales conservan el bloqueo por su idempotencia pendiente, conforme a la alternativa autorizada para esta entrega.

## A. Línea base

Repositorio existente, main, HEAD inicial `9bd7047`, árbol limpio. Se leyeron informes 1.5a/b/c y se inspeccionaron Cash, Core/transacciones, Users/Auth/permisos, Audit, Sales/modelos/apertura, rutas/layout, migraciones, salvaguardas y OpenAPI. Etapas anteriores presentes. Laravel 13.42.0, PHP 8.3.36, Sanctum 4.4.0, Livewire 4.4.8 y SQL Server Express 17.0.1155.3, Windows Authentication.

Línea base ejecutada antes de editar: **381 pruebas / 3653 aserciones, 6:14.092, 112 MB**, sin fallos. Pint correcto; Larastan **nivel 5, 253 archivos**, sin errores. Evidencias `.tools/cash-1.5d1-baseline-*.log`. Se capturan hashes de filas de las 18 tablas operativas y diez archivos protegidos, sin publicar secretos.

Cash solo contenía ActiveCashSession y estructura modular. Sales tenía cash_session_id sin FK y adaptador de rechazo. Se reutilizan TransactionManager, IdentityAccess, permisos, SecurityEvent/Audit, Core REST, Sanctum, sesión Web y middleware Livewire. No se reconstruye ningún módulo.

## B. Modelo de datos

| Tabla | Datos y protección |
| --- | --- |
| cash_registers | ULID, slot único igual a 1, name único de 150, is_active y timestamps. SQL limita esta etapa a una caja física, incluso inactiva. No implementa múltiples locales. |
| cash_sessions | ULID, register_id, opened_by, status, opening_fund decimal(19,4), opened_at UTC, closed_at/closed_by opcionales y timestamps. opening_key_hash y opening_fingerprint de 64 caracteres respaldan reintentos durables. |

ULID nativo/HasUlids, **nchar(26)** comprobado en SQL. Estados open/closed; no hay operación productiva de cierre. CHECK exige fondo no negativo y coherencia de estado/cierre/usuario/fecha. Índice único filtrado **cash_one_open_session** por caja cuando status=open; único `(opened_by, opening_key_hash)` e índices por caja/fecha y estado/fecha.

Tres FK Cash y nueva FK Sales→Cash, todas **NO ACTION**. Relaciones Eloquent internas CashRegister.sessions/CashSession.register. Los modelos impiden borrado físico; FK protegen registros relacionados. No se importan modelos privados de Users ni Cash desde Sales.

## C. Reglas funcionales

`cash:provision` y `Modules\Cash\Infrastructure\Seeders\CashRegisterSeeder` reutilizan ProvisionCashRegister. Crean **Caja principal**, activa, solo si falta. Repetir conserva ID/nombre/estado, incluida desactivación previa. No crean sesiones, usuarios ni ventas. La restricción slot=1 se deberá ampliar explícitamente al introducir varias cajas, sin cambiar IDs/relaciones existentes.

Apertura explícita: actor activo, contraseña cambiada, cash.open, caja existente/activa, fondo válido y ausencia de otra sesión abierta. No ocurre durante login. Fondo como **texto decimal**, hasta 15 enteros/cuatro decimales, admite cero; rechaza negativos, números JSON/float, exponentes, comas y redondeos implícitos. Salida con cuatro decimales. No equivale a ingreso por ventas ni genera movimientos.

`current` retorna sesión abierta o null; ausencia no implica cierre financiero previo. Se conserva historia y se permiten sesiones consecutivas del mismo día cuando el cierre exista. Desactivar requiere cash.administrative.execute y ausencia de sesión abierta; solo servicio interno, sin administración completa de cajas.

## D. Arquitectura

Cash depende de **Core y Users**. Sales conserva Core/Users/Catalog/Cash. Matriz y analizador verifican límites públicos y ausencia de ciclos. Cash no conoce Sales en PHP; la FK pertenece a Sales.

| Capa | Responsabilidad |
| --- | --- |
| Contracts | CashOperations, ActiveCashSession existente, RegisterSnapshot/SessionSnapshot readonly y errores públicos CashConflict/CashSessionUnavailable. |
| Domain | OpeningFund valida/normaliza el fondo exacto. |
| Application | ManageCash coordina autorización/locks/repositorio/auditoría; ProvisionCashRegister aprovisiona. CashStore es puerto interno. |
| Infrastructure | EloquentCashStore, modelos y seeder. Repositorio exige transacción externa; no abre transacciones independientes. |
| Http/Policies | Form Request estricto, Resources de DTOs, controlador delgado, CashPolicy, middleware persistente y CashDesk Livewire. |

Sin dependencias Composer nuevas, Docker, Node, Vite, colas o servicios externos. Core solo amplía la lista cerrada de eventos; no incorpora reglas de Cash.

## E. Integración Sales

ManageCash implementa realmente **ActiveCashSession**. Exige transacción externa, valida identidad, bloquea caja/sesión y devuelve ULID real. Sesión original distinta, ausencia/cierre o caja inactiva rechaza. Locks hasta commit externo en la misma conexión Core; sin flags ni sesiones ficticias productivas.

Se conservan autorización Sales, caja original, mostrador/mesa, ocupación, snapshots/precios históricos y folio null. FK incremental comprueba referencias huérfanas y **aborta sin reescribir historia** si existen. POS muestra referencia operativa activa sin fondo para usuarios Sales.

Edición absoluta de cantidades/notas y pausa/recuperación de ventas existentes requieren permisos, revisión vigente y caja original. Pruebas de dominio crean ventas de fixture con Cash real y validan asociación/historia/mesa única.

**Apertura y agregado desde API/POS siguen bloqueados con 503**, aun con caja abierta: CriticalSalesWrites requiere idempotencia durable de Sales todavía pendiente. Es la alternativa expresamente permitida en la sección 13 de la solicitud. Idempotencia Cash no sustituye la de Sales ni habilita adaptadores ficticios. Con cero ventas operativas previas no existen ventas que editar hasta habilitar apertura segura.

Retirada/cancelación siguen condicionadas a motivos pendientes. Sin cobros, pagos, cierre/reapertura pagada, descuentos, folios ni tickets.

## F. Idempotencia

Clave `[A-Za-z0-9_-]{16,128}`: Idempotency-Key en API y clave generada/bloqueada en Web. SHA-256 de clave por actor y SHA-256 de caja/fondo canónicos guardados en la propia sesión histórica. Sin tabla, memoria o cache paralelo ni TTL que permita repetir la apertura.

Misma transacción: reautorizar → bloquear caja → buscar replay → validar disponibilidad → insertar sesión/Audit → commit. Mismo actor/clave/contenido recupera sesión persistida, sin nueva apertura/evento. Contenido distinto o nueva clave ante caja abierta devuelve 409. Otro actor no recupera el recibo ajeno. Replay después de un futuro cierre devuelve esa sesión cerrada; nunca la reabre. Nueva sesión requiere nueva clave y disponibilidad.

Tras pérdida de respuesta reenviar misma clave/contenido. No hay reintentos automáticos de transacción. Refresco de fila tras insert absorbe redondeo datetime de SQL Server, haciendo coincidir primera respuesta/replay. Se prueba replay con kernels independientes; no se afirma haber provocado una caída real de red tras commit. Los recibos SQL sobreviven al proceso.

## G. Seguridad

Permisos reutilizados, sin cambiar roles: cash.view para pantalla/consulta/fondo; cash.open para apertura, además de cash.view en los transportes; cash.administrative.execute para estado físico. Meseros no reciben permisos financieros. Sin bypass admin.

Sanctum/auth.access.api mantienen bearer vigente, identidad activa y permisos actuales. Cookie Web no autentica API. Web conserva CSRF, sesión y middleware persistente; acciones revalidan contexto de empleado y permisos. Actor/estado/fechas/hashes no vienen del payload. Campos desconocidos se rechazan.

Actor, clave y mensaje de estado están #[Locked]. Caja se obtiene del servicio; solo fondo es intención editable. Consulta global autorizada cash.view: caja física compartida, no se inventa propiedad por empleado. Pruebas cubren anónimo, inactivo, sesión/token revocados, permiso retirado, contexto diferente y campos falsificados.

SensitiveErrors cubre /cash y cash.desk; Core mantiene errores API. Sin SQL/rutas/excepciones en debug activo o inactivo. Interceptor Livewire solicita JSON y muestra aviso genérico conservando entradas; no interpreta cuerpos internos ni almacena credenciales en navegador.

## H. Auditoría

Eventos centrales nuevos: cash.session.opened, cash.open.rejected, cash.register.updated. Apertura/cambio administrativo se auditan desde el servicio en la misma transacción; fallo Audit revierte la sesión. Replay/no-op no duplica éxito. Conflictos relevantes se auditan como blocked fuera de la transacción revertida.

Listener existente registra actor, sesión/caja, resultado, request_id, canal y fecha UTC. record_id de apertura es sesión, cuya FK identifica caja/responsable. Fondo, clave, token, nota y secretos no se copian a Audit: su contrato cerrado no admite payload financiero libre. Fondo autorizado se consulta en Cash. No se duplican eventos en HTTP/Livewire.

## I. SQL Server

Migraciones:

- `Modules/Cash/database/migrations/2026_11_17_000007_create_cash_tables.php`.
- `Modules/Sales/database/migrations/2026_11_17_000008_link_sales_to_cash_sessions.php`.

No se recrean tablas Sales ni snapshots. Down solo admite _test; TestDatabaseGuard verifica destino real y excluye fresapos. Fixtures históricas Sales declaran su dependencia no disponible/dobles; CashSqlServerTestCase instala Cash/FK/adaptador reales. No se eliminan pruebas anteriores ni se debilitan sus reglas.

Coordinación identidad global → caja → sesión → operación Sales; mismo protocolo para futuro cierre. UPDLOCK/HOLDLOCK e índice filtrado protegen concurrencia. Lock global conservador serializa escrituras/permisos; no se afirma capacidad de carga medida.

Seis carreras con procesos PHP/SPID SQL independientes y espera real LCK_M_U; SQL directo espera LCK_M_X y recibe 2601. Casos: dos empleados abren; replay mismo ID; índice rechaza duplicado; consulta espera apertura; transición de fixture invalida venta; dos ventas ocupan misma mesa con Cash real. Transiciones cerradas existen solo en fixtures SQL, sin cierre productivo.

Después de la suite completa y controles de calidad se creó **fresapos_pre_1_5d1_20261117_161754Z_ba5a5ea5.bak**, fuera del repositorio: **7 356 416 bytes**, COPY_ONLY, CHECKSUM, sin sobrescritura. **RESTORE VERIFYONLY WITH CHECKSUM, STOP_ON_ERROR** pasó a las **16:17:54 UTC**. Metadata/ruta privada en `.tools/cash-1.5d1-backup.json`. VERIFYONLY no equivale a una restauración de ensayo; no se afirma haberla realizado.

Aplicación real incremental: Cash **lote 6**, FK Sales **lote 7**, ambas Ran. Se ejecutó cash:provision: una caja física activa, sin sesiones ni ventas. FK Sales habilitada/confiable (is_disabled=0, is_not_trusted=0). Sin migrate:fresh, db:wipe, refresh ni rollback operativo.

```powershell
.\scripts\local.cmd php artisan migrate --path=Modules/Cash/database/migrations/2026_11_17_000007_create_cash_tables.php --no-interaction
.\scripts\local.cmd php artisan migrate --path=Modules/Sales/database/migrations/2026_11_17_000008_link_sales_to_cash_sessions.php --no-interaction
.\scripts\local.cmd php artisan cash:provision
```

## J. API y Livewire

| Método/ruta | Resultado |
| --- | --- |
| GET /api/v1/cash/registers | Única caja física, cash.view. |
| GET /api/v1/cash/sessions/current | session DTO autorizado o null, cash.view. |
| POST /api/v1/cash/sessions | register_id, opening_fund e Idempotency-Key; cash.open + cash.view. |
| GET /cash | Estado, responsable, apertura UTC, fondo/formulario según permisos. |

Apertura devuelve **200 inicial y replay**, sin afirmar una creación nueva en replay. Core success/code/message/data/meta.request_id, X-Request-Id y no-store. Decimales/ULID texto y fechas UTC ISO. Errores Core 401/403/404/409/422/429/500/503. No se serializan modelos/hashes/credenciales.

OpenAPI 3.1 incorpora contratos, claves, permisos y errores. Validador amplía seguridad/cobertura Cash sin reducir Catalog/Sales. Validación final: **45 operaciones**, cero errores/advertencias; **166 respuestas reales / 35 operaciones Catalog+Sales+Cash**, cero errores/advertencias. Las otras diez operaciones Core/Auth mantienen sus pruebas de regresión; no se presentan como muestras de ese archivo de respuestas.

## K. Calidad

Comandos PowerShell desde la raíz:

```powershell
.\scripts\local.cmd composer test
.\scripts\local.cmd composer lint
.\scripts\local.cmd composer analyse
.\scripts\local.cmd composer validate --strict
.\scripts\local.cmd composer check-platform-reqs
.\scripts\local.cmd composer openapi
powershell.exe -NoProfile -ExecutionPolicy Bypass -File scripts/validate-openapi.ps1 -Responses .tools/cash-1.5d1-all-responses.json -Report .tools/cash-1.5d1-responses-report.json
.\scripts\local.cmd php artisan route:list --except-vendor
.\scripts\local.cmd php artisan migrate:status
```

Suite completa: COMPOSER_PROCESS_TIMEOUT=900 solo del proceso, restaurado en finally. No cambia Composer ni entornos. Focalizadas iniciales: **46 pruebas / 674 aserciones, 39.325 s, 68 MB**. Feature después de ajustes: **179 pruebas / 785 aserciones, 9.939 s, 68 MB**.

| Control final ejecutado | Resultado real |
| --- | --- |
| composer test | **423 pruebas / 4091 aserciones, 7:22.847, 122 MB**, sin fallos, errores u omisiones. |
| Diferencia frente a baseline | **+42 pruebas / +438 aserciones**, conservando las 381 anteriores. |
| composer lint | Passed. |
| composer analyse | **Nivel 5, 281 archivos**, sin errores; baseline 253. |
| composer validate --strict / check-platform-reqs | Correctos; sin dependencias nuevas ni cambios de lock. |
| OpenAPI formal/rutas/respuestas | 45 operaciones; 166 respuestas / 35 operaciones; cero errores/advertencias. |
| route:list --except-vendor | **62 rutas propias**, frente a 58: tres API Cash y GET /cash. |
| HTTP nativo después de migrar | /, /up, /api/v1/ping, /login y JS Livewire **200**; Cash/POS/Catalog sin sesión **302 al login**. |
| Login/Livewire/Catalog/POS/Cash autenticados | Suite SQL/HTTP/Livewire y Chrome aislado satisfactorios; sin usar credenciales operativas. |
| migrate:status | Ocho migraciones Ran; nuevas en lotes 6 y 7. |
| fresapos_test al terminar | **Cero tablas**. |
| git diff --check | Correcto. |

Fallos conservados: dos diferencias datetime corregidas refrescando SQL; composición inicial OpenAPI anidada en PowerShell, corregida enumerando muestras. Primera suite completa: **405 pruebas / 4071 aserciones, dos fallos, 7:02.940, 120 MB** por expectativas históricas de rutas y Cash aún no implementado. Se amplía lista de rutas y se comprueba binding real conservando prueba del adaptador de rechazo. Se mueven 18 casos de fondo de Unit a Feature para que composer test los descubra, sin cambiar phpunit.xml. Logs fallidos no cuentan como aprobados. No quedan pruebas críticas locales pendientes.

Evidencias privadas: `.tools/cash-1.5d1-final-tests-second.log`, `cash-1.5d1-final-lint-second.log`, `cash-1.5d1-final-analyse-second.log`, `cash-1.5d1-feature-recheck.log`, `cash-1.5d1-responses-report.json`, seis `cash-1.5d1-race-*.json`, `cash-1.5d1-native-http-final.json` y logs de migraciones/respaldo. No se versionan diagnósticos privados ni secretos.

## L. Revisión visual

Computer-use falló dos veces al iniciar kernel (`trusted Node process exited unexpectedly`). Alternativa: **Chrome 157.0.8103.51 headless real**, DevTools, JavaScript/Livewire activos; QA 127.0.0.1:8083, perfil y datos separados, solo fresapos_test.

**22 capturas**: formulario/POS sin caja y Cash/POS con caja en 1440×900, 1024×768, 768×1024, 390×844; además caja sin aprovisionar, error conservando fondo, confirmación, mesero sin fondo/acceso y sesión revocada. Cero errores JS, desbordamientos horizontales o botones visibles menores de 44 px. Inspección visual de escritorio/móvil.

Apertura QA: una sesión, fondo 125.5000 y un evento de éxito. Venta POS preparada mediante servicio de dominio real/Cash real en esa misma fixture; apertura UI/API siguió bloqueada por idempotencia Sales. Mesero ve referencia operativa, nunca fondo; /cash 403. Revocación SQL real produce aviso genérico en Livewire. [Evidencias](qa/1.5d.1/README.md). Procesos cerrados/fixtures retiradas; cero tablas antes de regresión. No es auditoría exhaustiva de accesibilidad.

## M. Integridad

Protegidos: .env/.env.testing, database/session/filesystems, composer.json/lock, phpstan.neon, phpunit.xml y seguridad Users. Comprobación intermedia **10/10 idénticos**. No cambia Windows Authentication, administrador, contraseña o matriz de roles.

Comparación final: **17/17 tablas de datos previas idénticas** y **las seis filas originales de migrations intactas**. Solo se agregan dos filas de migración, las dos tablas Cash y una caja física. Veinte tablas operativas; **una caja y cero sesiones**, cero ventas/líneas/mesas. Se conservan un usuario/administrador activo, cuatro roles/44 permisos, dos categorías y dos productos. Los diez archivos protegidos son idénticos byte a byte, incluidas credenciales/configuración. FK nueva real y confiable.

Evidencias `.tools/cash-1.5d1-integrity-before.json`, `cash-1.5d1-integrity-pre-migration.json`, `cash-1.5d1-integrity-final.json`, `cash-1.5d1-migration-prefix.json`, `cash-1.5d1-protected.json` y `cash-1.5d1-final-integrity-report.json`. No se abrieron sesiones operativas como prueba ni se restauraron filas de usuario. HEAD conserva `9bd7047`; sin commits/pushes.

## N. Pendientes

Idempotencia durable Sales antes de abrir/agregar operativamente; motivos autorizados; pagos/movimientos, arqueo ciego, cierre definitivo caja/ventas, folios, reaperturas pagadas, descuentos y tickets. Estado closed prepara integridad, no implementa cierre.

Azure SQL/cPanel pendiente con credenciales autorizadas: ODBC/pdo_sqlsrv/sqlsrv, TLS/firewall, permisos, PHP 8.3/ionCube y respaldo del proveedor. Solo Express local verificado; no se conecta Azure ni se afirma despliegue validado allí. Sin avance a 1.5d.2.

**ETAPA 1.5d.1: ACEPTADA LOCALMENTE**. Cash, seguridad, idempotencia de apertura de caja, concurrencia SQL, API/UI, respaldo, migraciones e integridad verificados. La apertura/agregado de Sales continúa deliberadamente bloqueada por su idempotencia durable pendiente. No requiere nueva confirmación para este alcance. La entrega se detiene aquí, sin iniciar 1.5d.2.
