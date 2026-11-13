# Entrega 1.5a — Dominio y persistencia de Sales

Fecha: **13 de noviembre de 2026**. Windows/PowerShell, PHP 8.3.36, Laravel 13.42.0 y SQL Server Express `.\AUDITORIA`, con autenticación integrada de Windows. Sin Docker, Node ni dependencias nuevas.

Este informe cubre ventas de mostrador y mesas en dominio, servicios de aplicación y persistencia. No incorpora rutas de Sales, interfaz POS, pagos, Cash funcional, tickets o inventario. **La apertura operativa permanece bloqueada hasta disponer de Cash real.**

## A. Diagnóstico inicial

Inspección antes de modificar: Git, arquitectura y módulos, informes 1.3/1.4, Sales, Catalog, Core/transacciones, Users/Auth/permisos, Audit, migraciones y salvaguardas de pruebas. Rama `main`, HEAD inicial `187dc79`, working tree limpio. Sales y Cash solo tenían sus esqueletos; no existían entidades, tablas ni servicios funcionales de ventas.

Componentes reutilizados: `CatalogReader::forSale` y su snapshot readonly; `IdentityAccess`/`UserSnapshot` y permisos efectivos; `TransactionManager`; `SecurityEvent` y listener Audit existente; ULID/relaciones/casts Eloquent; pruebas SQL aisladas. No se modificó Catalog, su precio ni su contrato.

Línea base real ejecutada: **273 pruebas / 2454 aserciones**, 203,833 s, 94 MB; Pint correcto; Larastan nivel **5**, **197 archivos**, sin errores; Composer validate --strict y check-platform-reqs correctos. Los valores históricos se comprobaron, no se asumieron. Logs privados `.tools/sales-1.5a-baseline-*`.

Antes de implementar se capturaron hashes privados de todas las filas operativas y diez archivos protegidos. El usuario confirmó **100 unidades por línea** como máximo global inicial. No se modificaron credenciales, roles ni sus permisos.

## B. Modelo de datos

Una migración incremental: `Modules/Sales/database/migrations/2026_11_13_000006_create_sales_tables.php`. Todas las claves propias son ULID `nchar(26)`, con `HasUlids`; fechas de aplicación UTC y timestamps Eloquent.

| Tabla | Campos y restricciones principales |
| --- | --- |
| `sales_tables` | id, name nvarchar(150) único CI_AI_SC, is_active, display_order int no negativo, created_at/updated_at. No almacena ocupación ni tiene borrado lógico: se desactiva y conserva. |
| `sales_sales` | id, type, status, table_id nullable, created_by, cash_session_id, note nvarchar(150), subtotal/discount_amount/total decimal(19,4), folio nullable nvarchar(12), closed_at, cancelled_at/by/reason y timestamps. |
| `sales_items` | id, sale_id, product_id, product_name histórico, category_id/category_name históricos, unit_price decimal(19,4), quantity int positivo, note nvarchar(100), line_total decimal(19,4), position, removed_at/by/reason_code y timestamps. |

**Siete FK reales con NO ACTION**, sin cascadas: venta→mesa/creador/cancelador; línea→venta/producto/categoría/usuario que retira. Las referencias SQL a tablas ajenas preservan integridad; los modelos Eloquent no importan modelos privados de esos módulos. Las relaciones Eloquent dentro de Sales son mesa→ventas, venta→mesa/líneas y línea→venta.

`cash_session_id` es una **referencia contractual ULID sin FK por ahora**: Cash no tiene tabla destino y no se inventó una. La implementación futura deberá incorporar su FK real y validar referencias antes de habilitar operaciones. Hoy no pueden generarse referencias operativas mediante el servicio porque el adaptador registrado rechaza las operaciones financieras. El código de motivo de retiro también queda condicionado a un proveedor real; no se creó un catálogo ficticio.

Índices: ventas por estado/tipo/fecha/id, usuario/estado/fecha, mesa/estado y sesión contractual; mesas activas/nombre; líneas por venta/posición única, venta/retirada/posición, producto y categoría. Índices únicos filtrados para **una venta activa por mesa** y **folio no nulo único**; permiten múltiples ventas de mostrador y folios nulos.

CHECKs: tipo↔mesa, estados válidos, pausa solo mostrador, importes no negativos/total=subtotal−descuento, coherencia de cierre/folio/cancelación, folio de doce dígitos con fecha válida y secuencia no cero, cantidad/posición positivas, importe de línea exacto y coherencia de metadatos de retirada. La suma entre filas se mantiene en el servicio bajo lock; no se presenta como una restricción CHECK entre tablas.

## C. Estados y tipos

Tipos: `counter` y `table`. Los valores se representan mediante enums de dominio.

| Estado inicial | Operación disponible | Estado resultante |
| --- | --- | --- |
| Sin venta | Abrir, con caja activa validada | open |
| open de mostrador | Pausar | paused |
| paused de mostrador | Recuperar, misma caja aún válida | open |
| open o paused sin cierre financiero | Cancelar, con permiso y motivo | cancelled |
| closed / reopened / cancelled | Edición, pausa, recuperación genérica o cancelación ordinaria | Rechazadas |

Solo `open` permite editar consumos/notas. No se pausan ventas de mesa. `closed` y `reopened` se contemplan en el dominio/esquema, pero **no existen métodos close/reopen**, ni cierre ficticio, pagos o generación de folios. Una futura corrección de mesa en estado reopened también ocupa la mesa según el índice; modificarla requerirá la implementación financiera correspondiente.

El folio se reserva para cierre futuro con formato `YYYYMMDD0001`: secuencia diaria transaccional, fecha del negocio y conservación al reabrir. No se asigna al abrir/pausar ni se implementa una secuencia anticipada.

## D. Reglas de negocio

- Mostrador no admite mesa; venta de mesa exige una existente, activa y libre. La ocupación se deriva de ventas open/reopened. Cancelar una venta abierta libera la mesa sin modificar un flag redundante.
- Las mesas tienen nombres configurables, normalización Unicode y unicidad SQL insensible a mayúsculas/acentos; orden predeterminado **alfabético por nombre/id**, conservando display_order como dato administrativo. Desactivarlas impide nuevas aperturas y conserva referencias.
- Agregar un producto consulta `CatalogReader::forSale`: rechaza producto/categoría no disponibles y copia producto, nombre, categoría y precio vigentes. La imagen no se duplica en las líneas.
- La consolidación requiere igualdad estricta de producto, nombre, categoría id/nombre, precio y nota normalizada. Conserva ID/posición de la primera línea. Diferencias de precio, nota, capitalización significativa o snapshot crean otra línea. Las posiciones retiradas no se reutilizan.
- Cambiar Catalog no actualiza líneas previas. Aumentar una cantidad exige producto disponible y snapshot aún coincidente; si cambió, se rechaza y debe utilizarse agregar producto para incorporar unidades con datos vigentes. Disminuir conserva el precio histórico incluso si el producto fue eliminado/desactivado.
- Cantidades **enteras reales** de 1 a **100**; se rechazan fracciones, floats, cadenas numéricas, booleanos y overflow, también al consolidar. `sales.max_quantity` fija el valor; el contrato QuantityLimits permite el futuro override por producto sin agregar campos a Catalog en esta etapa.
- Cantidad cero invoca el flujo de retirada, nunca deja una línea activa inválida. Requiere sales.open.update, sales.special.authorize, motivo y aprobación real. Sin catálogo/proveedor de motivos se rechaza. Las pruebas usan un aprobador exclusivamente bajo Tests. La línea retirada conserva cantidad/precio/nota y metadatos, y se excluye de los importes activos.
- Nota de línea 100 y nota general 150 unidades Unicode de almacenamiento, coherentes con SQL/convención existente. Se normaliza NFC y espacios, conservando capitalización; vacíos opcionales pasan a null. Se rechazan controles, HTML delimitado por `<`/`>` y exceso de longitud. El transporte futuro también deberá escapar texto al visualizarlo.
- Los totales se recalculan en el servidor a partir de snapshots y cantidades; los comandos no admiten precio aplicado, subtotal, total, estado, folio ni identificador de autorizador/auditoría enviados como autoridad.
- Cancelar exige sales.cancel y motivo plano no vacío; conserva líneas/importes y registra actor/fecha UTC. Es terminal. No se implementan cancelaciones pagadas ni reembolsos. Los futuros cálculos de ingresos deberán excluir canceladas; no se implementó un reporte de ingresos aquí.

`Amount` calcula suma, resta y multiplicación sin float ni escalado completo a PHP int: decimal(19,4) puede superar PHP_INT_MAX. Opera por dígitos, mantiene cuatro decimales, rechaza overflow y total negativo. Se contrasta con aritmética decimal real de SQL Server en límites de acarreo/precisión.

Descuentos: existe `DiscountProposal` para importe fijo exacto o porcentaje entero 1–100 y un campo discount_amount por venta. **No hay operación de aplicación/autorización**: permanece en cero por los servicios actuales. La futura implementación deberá validar sales.discounts.apply/autorización especial, un único descuento, límites, redondeo porcentual acordado y sesión de caja; no se inventó ese proceso.

## E. Arquitectura

| Capa | Implementación |
| --- | --- |
| Domain | SaleType, SaleState, SaleLifecycle, SalesText, Amount, SalesConflict. Invariantes separadas de persistencia/transporte. |
| Contracts | SalesOperations, OpenSale/AddProduct, snapshots readonly, consultas tipadas/páginas, QuantityLimits, LineRemovalApproval, DiscountProposal y evento público SaleChanged. |
| Application | ManageSales coordina identidad, permisos, caja, catálogo, lock, persistencia, recálculo y auditoría dentro de TransactionManager. SalesStore es puerto interno, no API entre módulos. |
| Infrastructure | Entidades Eloquent Sale/SaleItem/DiningTable, EloquentSalesStore, límite global y adaptadores explícitos que rechazan dependencias ausentes. |
| Policies/Providers | SalesPolicy, bindings y mapeo seguro de SalesConflict a CONFLICT de Core. |
| Http | No se añade: se implementará con contenido real en 1.5b. Sin rutas/controladores de ejemplo. |

Operaciones disponibles por contrato: consultar/listar ventas y sus líneas, consultar/listar/configurar mesas/disponibilidad, abrir estructura, agregar producto, cambiar cantidad, editar notas, retirada condicionada, pausar, recuperar y cancelar venta sin cierre. No hay actualización genérica de registros financieros.

Listados de ventas y mesas paginan en SQL (10/25/50/100, 25 predeterminado). Consultas tipadas limitan filtros; SQL usa bindings y escapa comodines de búsqueda de mesa. Ventas se filtran por estado/tipo/mesa/usuario. Los listados no cargan líneas ni ejecutan una consulta por mesa: ocupación mediante EXISTS. La consulta de una venta carga sus propias líneas en una consulta ordenada, necesarias para su agregado; no carga todas las ventas del sistema.

## F. Integración

La matriz permite Sales→Core/Users/Catalog/**Cash por su nuevo contrato público**. Cash continúa dependiendo solo de Core y Catalog no depende de Sales. No se importan modelos/repositorios privados de otros módulos ni hay HTTP interno.

`Cash\Contracts\ActiveCashSession::requireActive(actorId, expectedSessionId)` debe verificar actor, sesión abierta, pertenencia/validez y sesión original cuando se indica. El adaptador funcional futuro debe mantener su lock hasta el commit de la **misma conexión/transacción Core**, coordinado con el cierre de caja; una lectura sin lock no cumple el contrato.

Actualmente se registra `UnavailableCashSession`: **lanza SalesConflict**, sin crear sesiones falsas, filas de Cash ni ventas operativas. Todos los cambios de consumo/estado también exigen la caja original disponible. Las consultas autorizadas y configuración administrativa de mesas no simulan caja. `TestCashSession` solo existe en Tests y cada prueba elimina sus datos de fresapos_test.

`LineRemovalApproval` requiere validación contra el catálogo autorizado y actor; su adaptador actual rechaza. No hay catálogo improvisado. El código TEST_APPROVED solo pertenece a fixtures que se desmontan.

El actor se pasa separado de los DTO de negocio y se vuelve a inspeccionar en cada operación. El transporte de 1.5b deberá derivarlo de la sesión/token y del **empleado activo tras PIN**, nunca de un actorId del payload o del propietario anterior de la terminal. Se conservan las regresiones Auth/PIN y se prueban capacidades actuales/revocadas en Sales.

## G. Concurrencia

Orden actual: coordinación de identidad existente (`UPDLOCK/HOLDLOCK`), contrato de caja, mesa/venta con lock y líneas. Los repositorios exigen transacción activa, no abren otras. El lock de identidad conserva la coherencia con cambios de permisos y escrituras de Catalog; serializa las escrituras autorizadas y deberá considerarse al medir carga y coordinar Cash futuro.

La mesa se bloquea al abrir y se consulta su disponibilidad dentro de la transacción. Además, SQL Server impone **sales_one_active_table**, único por table_id donde no es null y status IN (open,reopened). Esta defensa cubre escritores que no usen el servicio. No se implementaron reintentos ciegos o idempotencia ficticia.

Prueba real con **dos procesos PHP y dos SPID SQL distintos**: el primero inserta sin commit; el segundo intenta abrir y se observa su bloqueo en sys.dm_exec_requests; se libera el primer commit y el segundo recibe conflicto. Se repite mediante servicios (espera LCK_M_U) y mediante inserciones SQL sin servicios (LCK_M_X, error **2601** del índice). En ambos casos queda **una sola venta activa**, nunca dos. No son mocks ni llamadas secuenciales presentadas como concurrencia.

Fuentes: [índices filtrados de SQL Server/Azure SQL](https://learn.microsoft.com/en-us/sql/relational-databases/indexes/create-filtered-indexes?view=sql-server-ver17) y [sintaxis CREATE INDEX, predicado IN](https://learn.microsoft.com/en-us/sql/t-sql/statements/create-index-transact-sql?view=sql-server-ver17). La prueba se ejecuta en Express; no se afirma haberla ejecutado en Azure.

## H. SQL Server, respaldo y migración

La migración se ejecutó primero en fresapos_test mediante las fixtures reales y se revisó con `migrate --pretend` sobre el destino operativo. No sobrescribe tablas preexistentes. El down solo admite bases _test y desmonta hijos antes de padres; las fixtures además verifican conexión configurada y DB_NAME() y excluyen la conexión operativa.

Después de la suite completa y controles de calidad se creó **fresapos_pre_1_5a_20261113_182814Z_e02a84aa.bak**, fuera del repositorio, sin sobrescribir respaldos. SQL Server confirmó existencia posterior, COPY_ONLY, checksum y tamaño de conjunto **7 159 808 bytes**. **RESTORE VERIFYONLY WITH CHECKSUM, STOP_ON_ERROR** terminó correctamente a las **18:28:14 UTC**. La ruta exacta y metadata están en `.tools/sales-1.5a-backup.json`. No se afirma restauración de ensayo a partir de VERIFYONLY.

La migración incremental se aplicó realmente en fresapos, **lote 5**, y migrate:status confirmó Ran. Resultado: **18 tablas**, las quince anteriores más las tres de Sales vacías; no se cambiaron columnas o datos previos. No se utilizó migrate:fresh, db:wipe, reset o refresh. Las pruebas de SQL/concurrencia fueron exclusivamente en fresapos_test.

Comando incremental ejecutado después de comprobarlo con --pretend:

```powershell
.\scripts\local.cmd php artisan migrate --path=Modules/Sales/database/migrations/2026_11_13_000006_create_sales_tables.php --no-interaction
```

No se siembran mesas, ventas, líneas ni caja en fresapos. Las operaciones financieras permanecen bloqueadas por Cash incluso cuando exista el esquema.

## I. Seguridad y auditoría

Se mantienen los **44 permisos y cuatro roles**, sin conceder privilegios nuevos. Lectura sales.view; apertura sales.create; consumos/notas/pausa/recuperación sales.open.update; cancelación sales.cancel; retirada además sales.special.authorize. Configuración de mesas usa **settings.update**, capacidad administrativa ya existente para configuración global; no se inventó un permiso tables.manage ni se concedió a supervisor/cajero/mesero.

La Policy comprueba identidad activa, cambio de contraseña completado y permiso efectivo actual, sin bypass por nombre admin. Se prueba mesero sin cancelación/retirada/configuración, supervisor con cancelación, cajero sin ella, permiso retirado, usuario inactivo/inexistente/sin rol o con contraseña pendiente y rechazo de línea de otra venta.

Se amplía de forma aditiva la lista cerrada de SecurityEvent: apertura, pausa, recuperación, agregado, cantidad, notas, retirada, cancelación y configuración de mesa. No se crean tablas/listeners Audit paralelos ni payloads libres. Actor, venta/mesa afectada, resultado y fecha UTC se registran; notas/motivos/credenciales no se copian a Audit. Retirar/cancelar conserva sus metadatos propios en Sales. Los no-op de nota/cantidad no duplican auditoría.

Audit se escribe en la misma transacción y su fallo revierte el cambio no confirmado. Los eventos públicos secundarios SaleChanged se entregan después del commit externo; una excepción del consumidor se registra con información mínima y no convierte el commit en una operación fallida. Tampoco una caída del logger propaga ese fallo. No existe un outbox durable/reintento de consumidores en esta entrega; se documenta como limitación de entrega secundaria.

## J. Pruebas y calidad

Pruebas nuevas: SalesDomainTest, SalesTest y SalesConcurrencyTest; fixtures de Cash/motivos y worker concurrente exclusivos de Tests. Se amplía únicamente la expectativa exacta de migraciones de ModuleRegistrationTest para incluir Sales. No se eliminan pruebas previas, no se reducen aserciones ni Larastan.

Cobertura: tipos/estados/transiciones, ULID, relaciones y FK; apertura condicionada a caja, sesión original; snapshots históricos, consolidación/separación/orden, precios exactos y overflow; cantidades reales/límites/override, notas 100/150/HTML/controles; mostrador pausado, cancelación/retirada preservando historia; mesa activa/libre/ocupada, unicidad/orden/filtros/páginas; permisos actuales, rollback, auditoría UTC y eventos después de commit; cálculos contra SQL y concurrencia de servicio/índice.

Comandos ejecutados, PowerShell desde la raíz:

```powershell
# Para suites completas, ampliar solo el timeout del proceso y restaurarlo al terminar.
$previousTimeout = $env:COMPOSER_PROCESS_TIMEOUT
try {
    $env:COMPOSER_PROCESS_TIMEOUT = '900'
    .\scripts\local.cmd composer test
} finally {
    $env:COMPOSER_PROCESS_TIMEOUT = $previousTimeout
}
.\scripts\local.cmd php vendor/bin/phpunit --filter Sales
.\scripts\local.cmd php vendor/bin/phpunit tests/Integration/SalesConcurrencyTest.php
.\scripts\local.cmd composer lint
.\scripts\local.cmd composer analyse
.\scripts\local.cmd composer validate --strict
.\scripts\local.cmd composer check-platform-reqs
.\scripts\local.cmd composer openapi
```

| Comprobación | Resultado final real |
| --- | --- |
| composer test | **343 pruebas / 2736 aserciones**, **232,497 s**, **100 MB**, correcto, sin fallos/errores/omisiones |
| Diferencia frente a línea base | **+70 pruebas / +282 aserciones**, conservando las 273 anteriores |
| composer lint | Passed |
| composer analyse | **Nivel 5, 229 archivos**, sin errores, frente a 197 iniciales |
| Composer validate --strict / check-platform-reqs | Correctos, sin paquetes nuevos ni cambios de lock |
| OpenAPI formal + rutas | 25 operaciones API existentes, cero errores/advertencias; sin rutas Sales añadidas |
| OpenAPI + respuestas de regresión | 34 respuestas reales de las 15 operaciones Catalog, cero errores/advertencias |
| route:list --except-vendor | **40 rutas propias**, conservadas |
| HTTP nativo /, /up, /api/v1/ping, /login y JS Livewire | **200** |
| Pantallas Catalog sin sesión / API Catalog sin token | **302** a login / **401** |
| /api/v1/sales | **404**, conforme al alcance sin endpoints |
| Migración operativa / base de pruebas al terminar | Lote 5 Ran / **cero tablas** en fresapos_test |

Las 70 pruebas nuevas son **42 de dominio/datasets**, **26 de integración** y **dos concurrentes**. La ejecución focalizada previa pasó **71 pruebas / 275 aserciones**, antes de añadir el caso decimal contra SQL (incluye pruebas históricas cuyo nombre coincide con Sales). Concurrencia focalizada: **2 pruebas / 23 aserciones**. El nuevo caso decimal contra SQL pasó **1 prueba / 10 aserciones**. No se confunden estos filtros con la cantidad neta de pruebas añadidas. Login/logout, cambio PIN y protocolo Livewire autenticados conservan la cobertura de la suite, sin usar credenciales operativas en HTTP.

Durante la validación inicial se corrigieron tres expectativas de pruebas: siete FK reales en vez de ocho —Cash aún no tiene tabla—, normalización de metadata SQL devuelta como cadena '0' y cero inicial omitido por PDO en '.0001'. No se cambiaron restricciones o importes para hacer pasar las pruebas. No hubo errores de Larastan en los 229 archivos productivos analizados. Se conservan los logs iniciales con sus fallos y las ejecuciones satisfactorias; no se omiten errores para declarar aceptación.

Evidencias privadas: `.tools/sales-1.5a-final-tests.log`, `sales-1.5a-final-lint.log`, `sales-1.5a-final-analyse.log`, `sales-1.5a-race-application.json`, `sales-1.5a-race-unique_index.json`, `sales-1.5a-openapi-report.json`, `sales-1.5a-operative-migration.log`, `sales-1.5a-http.json` y `sales-1.5a-routes.json`. La suite completa ya valida los procesos concurrentes; no se repetirá sin cambios/fallos nuevos que lo justifiquen.

## K. Integridad

Se comparan hashes de todas las filas previas y de diez archivos: .env, .env.testing, config/database.php, config/session.php, config/filesystems.php, composer.json/lock, phpstan.neon, phpunit.xml y Modules/Users/config/security.php. La única diferencia de datos operativos admisible es el registro incremental de migración y las tres tablas nuevas vacías; usuarios, credenciales, roles/permisos, sesiones, Audit y catálogo previos deben permanecer intactos.

Resultado medido: **las catorce tablas de datos anteriores son idénticas**, y las **cinco filas previas de migrations también**; solo se agrega la fila de Sales. Se conservan **un usuario y un administrador activo**, cuatro roles, 44 permisos, dos categorías y dos productos operativos. Las tres tablas nuevas están vacías y no se inventaron datos de caja/motivos. Los **diez archivos protegidos son idénticos byte a byte**, incluidos Composer y su lock. La base fresapos_test quedó con cero tablas.

La comparación inicial/final y resumen están en `.tools/sales-1.5a-integrity-before.json`, `sales-1.5a-integrity-final.json` y `sales-1.5a-final-state.json`. Evidencias y backup metadata están ignorados por Git. No se imprimen hashes de contraseña ni cadenas de conexión. No se realizaron commits ni pushes; HEAD se conserva en 187dc79.

## L. Pendientes y preparación para 1.5b

- API REST de Sales: rutas, controladores, Requests/Resources, derivación del actor real desde Auth/PIN y extensión de OpenAPI. No se implementó UI POS.
- Cash funcional: sesión abierta real y autorización/bloqueo bajo la transacción compartida; FK a su tabla; cierre, folio/secuencia diaria y reapertura con integridad financiera. Sin esto, la apertura operativa sigue rechazada deliberadamente.
- Catálogo autorizado de motivos y adaptador real de LineRemovalApproval: la retirada operativa permanece bloqueada, aun teniendo permiso.
- Aplicación/autorización de un descuento manual por venta, porcentaje/redondeo acordado y coherencia financiera. DiscountProposal no aplica descuentos.
- Outbox/reentrega durable de eventos secundarios, idempotencia y mediciones de carga según las etapas correspondientes; no se declaran implementados mediante logging.
- Azure SQL y cPanel: credenciales autorizadas, TLS/firewall, ODBC/pdo_sqlsrv/sqlsrv, permisos, PHP 8.3/ionCube y ejecución real de migraciones/concurrencia. Solo se validó SQL Server Express local.

Los bloqueos de Cash/motivos son los límites expresamente solicitados de esta etapa, no implementaciones ficticias aceptadas como funcionales. El trabajo se detiene en 1.5a.

**ETAPA 1.5a: ACEPTADA LOCALMENTE**. Fundamentos de Sales implementados y probados, incluida concurrencia SQL real; 343 pruebas/2736 aserciones y calidad satisfactoria; respaldo verificado, migración incremental aplicada e integridad previa conservada. No se habilitan ventas operativas sin Cash ni se avanza a 1.5b.
