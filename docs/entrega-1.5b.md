# Entrega 1.5b — API REST de mesas y ventas

## A. Estado inicial

Inspección del 13 de noviembre de 2026: rama `main`, HEAD `187dc79`. Los cambios completos de 1.5a estaban presentes **sin commit** y se conservaron. Se revisaron informe, contratos, DTOs, repositorio, modelos, migración del lote 5, transiciones, snapshots, Cash, permisos/Policies, Audit, Core HTTP, rutas, OpenAPI y salvaguardas SQL. No se reconstruye Sales.

Versiones reales: Laravel **13.42.0**, PHP **8.3.36**, Sanctum **4.4.0**, SQL Server Express **17.0.1155.3**, autenticación Windows. `fresapos`: 18 tablas, un usuario/administrador activo, dos categorías y dos productos. Línea base ejecutada antes de editar: **343 pruebas / 2736 aserciones**, 4:07.814, 100 MB; Pint correcto; Larastan nivel 5, **229 archivos**, sin errores. No había idempotencia durable ni catálogos funcionales de motivos; Cash solo tenía contrato y rechazo de 1.5a.

## B. API de mesas

Cinco operaciones en `/api/v1/sales/tables`: GET colección, POST, GET/PUT `/{id}`, PATCH `/{id}/status`. Lectura con `sales.view`; creación con `settings.update`; edición requiere además lectura autorizada y revisión vigente. Filtros `search`, `is_active`, `is_occupied`, `page`, `per_page`; orden alfabético por nombre/ID; paginación SQL de 10/25/50/100. Se reutiliza el escape de comodines SQL existente.

Disponibilidad derivada `free`/`occupied`/`inactive`; `active_sale_id` se obtiene mediante subconsulta junto con ocupación, sin persistir estado redundante ni N+1, solo tras autorizar `sales.view`. ManageSales impide desactivar una mesa ocupada; cambiar su nombre conserva la relación histórica. No hay borrado físico ni mapas.

## C. API de ventas

| Método | Ruta relativa a /api/v1/sales | Permiso principal | Condición |
| --- | --- | --- | --- |
| GET | colección, /{id}, /{id}/items | sales.view | Disponible |
| POST | colección | sales.create | Idempotencia durable y Cash real |
| POST | /{id}/items | sales.open.update | Idempotencia, revisión y Cash original |
| PATCH | /{id}/items/{itemId}/quantity | sales.open.update | Revisión y Cash original |
| PATCH | /{id}/items/{itemId}/note | sales.open.update | Igual; nota de 100 |
| PATCH | /{id}/note | sales.open.update | Igual; nota de 150 |
| DELETE | /{id}/items/{itemId} | sales.open.update + sales.special.authorize | Además motivo y LineRemovalApproval reales |
| POST | /{id}/pause, /{id}/resume | sales.open.update | Mostrador, transición válida, revisión y Cash |
| POST | /{id}/cancel | sales.cancel | Cancelación no financiera; catálogo de motivos, revisión y Cash |

Las escrituras sobre una venta existente requieren también `sales.view` para coordinar el estado leído. Filtros: `state`, `type`, `table_id`, `created_by`, `date`, `order`, `page`, `per_page`. Día UTC `YYYY-MM-DD` con intervalo `[inicio, siguiente día)` sin convertir la columna SQL; orden `asc`/`desc` por fecha/ID. Paginación en servidor. Conforme a la Policy de 1.5a, `sales.view` permite consulta operacional global; no se inventa propiedad por vendedor. Una línea de otra venta devuelve 404.

## D. Validación HTTP

SalesRequest autoriza antes de validar y selecciona reglas por acción. Rechaza **todo campo desconocido**, incluidos actor/caja/precios/importes/folio/estado/auditoría. Nunca se asignan payloads HTTP a modelos. ULID de rutas inválido: 404; de cuerpo/filtros: 422. Tipos counter/table; mesa obligatoria para table y prohibida para counter. Cantidades **enteros JSON reales**, positivos, máximo global inicial 100; strings/floats/bools/cero se rechazan. La decisión final y overrides permanecen en QuantityLimits/Sales. Retirar una línea exige DELETE y motivo autorizado.

Notas HTTP 100/150; normalización NFC, texto plano, controles y capacidad física UTF-16 siguen en el dominio. `reason_code` cerrado en formato, validado por contrato de catálogo, sin sustituirlo por texto libre. Sin catálogos reales no se ejecuta la operación.

## E. Resources y respuestas

SaleResource, SaleItemResource y TableResource presentan DTOs públicos. Decimales como **texto exacto de cuatro posiciones**, fechas UTC ISO 8601. Detalle con historia de líneas, `removed_at` y `revision`; listado de ventas sin líneas. Para editar, consultar el detalle completo. No se expone `cash_session_id`, modelos completos, credenciales, secretos de sesión ni metadata transaccional.

ApiResponder conserva `success`, `code`, `message`, `data`, `meta.request_id`, `X-Request-Id` y no-store. Listados añaden `meta.page`, `meta.per_page`, `meta.total`. Errores existentes: 401 UNAUTHENTICATED/SESSION_EXPIRED, 403 FORBIDDEN, 404 NOT_FOUND, 409 CONFLICT, 422 VALIDATION_ERROR, 503 SERVICE_UNAVAILABLE y 500 INTERNAL_ERROR. Mesa ocupada/inactiva, producto no disponible, estado inválido y revisión antigua usan CONFLICT; CashUnavailable especializa SalesConflict con respuesta 503 y mantiene compatibilidad de dominio. No se crea otro esquema ni se filtra SQL/trazas con debug activo o inactivo.

## F. Seguridad

Las 17 rutas usan `auth.no-store`, `auth:sanctum`, `auth.access.api` y el límite API compartido. Identidad/permisos actuales, contraseña cambiada, token y acceso vigente; el servicio vuelve a autorizar dentro de la transacción. Actor exclusivamente desde autenticación. No se agregan permisos ni privilegios por nombre de rol.

La API existente es **solo bearer** (`sanctum.guard=[]`, sin dominios stateful). Una sesión web/PIN no autentica Sales API. Prueba real de login, PIN, autorización de terminal y cambio a empleado: cookie sola recibe 401; token propio del empleado no hereda administración y la auditoría se atribuye al empleado. El POS Web futuro deberá integrar sesión/CSRF en su entrega. Se prueban anónimo, sin permiso, sesión/token revocados, inactividad, actor falsificado y acceso a línea ajena. Las pruebas anteriores de Livewire y seguridad permanecen.

## G. Integración

Controladores delgados construyen DTOs, invocan SalesOperations y presentan Resources; no calculan importes, transiciones ni auditan. ManageSales reutiliza CatalogReader, QuantityLimits, snapshots y repositorio. Única regla adicional: no desactivar mesa ocupada. Users aporta identidad/Policy; Core, transacciones/respuestas; Audit registra los eventos existentes una sola vez por cambio efectivo. Sin acceso a modelos privados ajenos ni nuevas dependencias circulares.

CoordinateSalesWrite coordina precondición y servicio en Application; los repositorios no abren transacciones. Se comprueban actor, venta, conteos y ausencia de auditoría por rechazo/replay. UTC, rollback y eventos después del commit siguen cubiertos por la suite anterior.

## H. Concurrencia

Detalle/mesas incluyen `revision`: HMAC SHA-256 opaco del DTO completo con APP_KEY, incluyendo líneas/historia u ocupación. Enviar **`If-Match: "<revision>"`** al escribir un recurso existente. Coincidencia exacta; no admite `*`, weak tags ni listas. Ausente/antigua: **409**, volver a consultar y resolver el cambio.

La aplicación adquiere el mismo lock de identidad de todas las escrituras de ManageSales **antes de leer**, autoriza, compara y ejecuta el servicio en la misma transacción/conexión hasta commit. No usa timestamps como versión, ni rowversion, ni migración. Huella de contenido, no contador de cambios: si el estado vuelve exactamente a un contenido compatible puede coincidir. Cambiar APP_KEY invalida revisiones anteriores. El lock global es conservador y serializa escrituras; una optimización futura debe preservar atomicidad y orden de locks/Cash. SQL externo ajeno a los servicios queda fuera del protocolo.

Cinco pruebas con procesos PHP/kernels HTTP/SPID independientes: cantidades, nota, pausa, estado de mesa y apertura bloqueada. Se observa lock real en `sys.dm_exec_requests`. En los primeros cuatro: 200 primero/409 segundo, sin sobrescritura; en apertura sobre la misma mesa, dependencias productivas ausentes: 503 ambos/cero ventas o líneas. Se conservan las carreras anteriores del servicio e índice único SQL. No se presenta esto como prueba de carga ni de Cash inexistente.

## I. Idempotencia

No existía almacenamiento durable reutilizable. Apertura y agregado incremental necesitan idempotencia para no duplicar ventas/unidades. CriticalSalesWrites define la integración futura; su implementación productiva **rechaza siempre antes de ejecutar**. Sin almacenamiento provisional, cache, flags ni bypass. Ambas rutas exigen `Idempotency-Key` de 16–128 caracteres `[A-Za-z0-9_-]`; una clave válida no habilita operaciones: responden 503 mientras falten adaptador durable y Cash real.

Las demás mutaciones son asignaciones absolutas con revisión: el reintento confirmado recibe conflicto con revisión anterior, o no-op sin auditoría adicional si el contenido sigue idéntico. Mesas tienen unicidad de nombre; doble POST igual puede responder 409 sin duplicación.

El adaptador definitivo deberá persistir actor/alcance/clave, fingerprint canónico, resultado/referencia, exclusión concurrente, rechazo de clave incompatible, autorización actual de replay y atomicidad con Sales/Cash; definir retención y recuperación de resultado incierto. **No se declara implementado**. Fixtures bajo Tests sustituyen explícitamente Cash/motivos/idempotencia solo en testing. El fixture de replay usa memoria para probar cableado; **no acredita durabilidad, recuperación de caída ni concurrencia multiproceso** y nunca se registra en producción. Se mantienen bloqueadas las operaciones críticas conforme al apartado 18 solicitado.

## J. OpenAPI

Se amplía la especificación existente: **42 operaciones**, 15 Catalog y 17 Sales. Esquemas estrictos, ULID, filtros, paginación, permisos, If-Match, Idempotency-Key, errores y bloqueos operativos explícitos; sin rutas ficticias. Se reutilizan openapi-spec-validator/openapi-schema-validator **0.9.0**, sin paquetes nuevos. El validador verifica también middleware bearer de Sales; conserva cobertura completa Catalog y exige las 17 Sales cuando hay muestras de ese módulo.

Resultado final: **138 respuestas reales / 32 operaciones Catalog+Sales**, cero errores/advertencias. Las 42 operaciones documentadas coinciden exactamente con rutas y métodos reales. Incluye éxitos de fixtures y rechazos productivos, distinguidos en este informe.

## K. SQL Server

**Sin migraciones nuevas ni cambios de esquema**: se conservan lote 5 y las tres tablas Sales. ULID nchar(26), decimal(19,4), FK NO ACTION, CHECKs e índices únicos filtrados intactos. Cash no tiene FK destino ficticia. No se ejecutan migraciones, seeds, ventas ni mesas sobre fresapos; no se necesitó otra ventana de migración/respaldo. El respaldo checksum verificado de 1.5a sigue documentado allí.

Solo `fresapos_test` en integración: guard de entorno/conexión/nombre `_test` distinto de fresapos, eliminación de conexión operativa en memoria y cleanup de fixtures. Workers aplican la misma salvaguarda antes de tocar datos. Sin migrate:fresh/db:wipe, commits o pushes.

## L. Pruebas y calidad

PowerShell desde la raíz; comandos ejecutados:

```powershell
.\scripts\local.cmd php vendor/bin/phpunit tests/Integration/SalesHttpTest.php tests/Integration/SalesHttpConcurrencyTest.php
.\scripts\local.cmd php vendor/bin/phpunit tests/Feature/SalesApiContractTest.php
# Suite completa: timeout solo del proceso, restaurado al terminar.
$previousTimeout = $env:COMPOSER_PROCESS_TIMEOUT
try {
    $env:COMPOSER_PROCESS_TIMEOUT = '900'
    .\scripts\local.cmd composer test
} finally { $env:COMPOSER_PROCESS_TIMEOUT = $previousTimeout }
.\scripts\local.cmd composer lint
.\scripts\local.cmd composer analyse
.\scripts\local.cmd composer validate --strict
.\scripts\local.cmd composer check-platform-reqs
.\scripts\local.cmd composer openapi
powershell.exe -NoProfile -ExecutionPolicy Bypass -File scripts/validate-openapi.ps1 -Responses .tools/sales-1.5b-all-responses.json -Report .tools/sales-1.5b-response-report.json
.\scripts\local.cmd php artisan route:list --except-vendor
.\scripts\local.cmd php artisan migrate:status
```

Focalizadas satisfactorias: **16 HTTP/concurrencia / 671 aserciones**, 1:04.136, 60 MB; **4 unitarias / 15 aserciones**, 0.446 s. Suite completa final: **363 pruebas / 3439 aserciones**, **6:22.310**, **110 MB**, sin fallos, errores u omisiones. Frente a baseline: **+20 pruebas / +703 aserciones**; se conservan las 343 anteriores. El incremento incluye las nuevas aserciones de registro de rutas. No se debilitan pruebas anteriores; se amplían sus listas exactas de rutas/controladores.

| Comprobación final | Resultado |
| --- | --- |
| composer lint | Passed |
| composer analyse | Nivel 5, 244 archivos, sin errores; baseline 229 |
| composer validate --strict / check-platform-reqs | Correctos; ninguna dependencia nueva |
| OpenAPI formal y respuestas reales | 42 operaciones; 138 respuestas; 0 errores/advertencias |
| route:list --except-vendor | 57 rutas propias; baseline 40 |
| migrate:status | Seis migraciones existentes, última Sales en lote 5 Ran; ningún cambio |
| HTTP nativo /, /up, /api/v1/ping, /login, JS Livewire real | 200 |
| Catalog administrativo sin sesión / Sales sin token | 302 a login / 401 |
| fresapos_test al terminar | Cero tablas |
| git diff --check | Correcto |

El primer sondeo HTTP usó el antiguo `/livewire/livewire.js` y recibió 404; la ruta registrada real `/livewire-ba99715c/livewire.js` se comprobó después con 200. PowerShell no capturó inicialmente la redirección de Catalog; la comprobación nativa sin seguimiento confirmó 302. Son errores del sondeo, no regresiones de Livewire o autenticación. Evidencia final en `sales-1.5b-native-http-final.json`.

Fallos iniciales conservados: tres expectativas HTTP por controladores de fixture no renovados tras sustituir contratos y uso incorrecto de update para desactivar al usuario; se corrigieron tests con controladores frescos por request y UserAdministration::setActive. Primera generación OpenAPI con anclas YAML duplicadas: corregida sin aliases. Posteriores ejecuciones focalizadas/formales pasan. No se relajaron reglas ni esquemas.

## M. Integridad

Antes de editar: hashes SHA-256 de filas ordenadas de las **18 tablas**, incluido migrations, y diez archivos protegidos: .env/.env.testing, configuración de conexión/sesiones/filesystem, composer.json/lock, phpstan.neon, phpunit.xml y seguridad Users. No se imprimen credenciales ni hashes de contraseña. Comparación final: **18/18 tablas idénticas, sin tablas nuevas, diez archivos idénticos byte a byte**. Se conservan un usuario/administrador activo, cuatro roles, 44 permisos, dos categorías y dos productos. Sales/mesas/líneas operativas siguen vacías. Ningún cambio de credenciales o conexión Windows.

Evidencias privadas ignoradas: `.tools/sales-1.5b-integrity-before.json`, `sales-1.5b-integrity-final.json`, `sales-1.5b-protected.json`, logs baseline/final, capturas de respuestas, reportes OpenAPI y cinco `sales-1.5b-race-*.json`. No se publican tokens de fixtures. Los datos operativos y el trabajo anterior se conservan.

## N. Pendientes

Cash real y coordinación de sesión/cierre/FK; idempotencia durable antes de habilitar apertura/agregado; catálogos reales de motivos para retirada/cancelación. POS Web/Android, UI, cobros/cierre financiero, folios, tickets, inventario, división de cuentas, descuentos aplicados y outbox quedan fuera.

Azure SQL/cPanel: ejecución real pendiente con credenciales autorizadas, ODBC/pdo_sqlsrv, TLS/firewall, permisos y PHP/ionCube. Solo se valida Express local.

**ETAPA 1.5b: ACEPTADA LOCALMENTE**. API de consultas/mesas y capa HTTP de operaciones implementadas, 363 pruebas satisfactorias y calidad conservada, concurrencia SQL real y contrato OpenAPI validado, datos intactos. Los bloqueos operativos por Cash, idempotencia y motivos son explícitos y probados, conforme al alcance solicitado; no se declaran disponibles esas dependencias. Sin decisiones pendientes para aceptar este alcance ni commits/pushes. La entrega se detiene en 1.5b, sin iniciar 1.5c.
