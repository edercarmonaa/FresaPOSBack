# Entrega 1.5d.2a — Idempotencia durable de Sales

Estado: **ETAPA 1.5d.2a: ACEPTADA LOCALMENTE**. Fecha: 17 de noviembre de 2026. Este informe registra hechos locales; Azure SQL y hosting no se declaran verificados.

## A. Diagnóstico y línea base

Repositorio existente en Windows/PowerShell, PHP 8.3.36, Laravel 13.42, SQL Server Express `AUDITORIA`, conexión Windows a `fresapos`. Se inspeccionaron código, Composer, módulos, migraciones, rutas, pruebas y contratos. Se reutilizaron Sales, Cash, autorización, revisión If-Match, transacciones, DTOs y auditoría. Estado inicial de Git limpio, HEAD `42fea6d`; sin commits ni pushes en esta entrega.

Línea base ejecutada antes de editar: **423 pruebas / 4091 aserciones**, 7:41.868, 122 MB; Pint y Larastan nivel 5 (281 archivos) sin errores. Composer no recibe dependencias nuevas. La base operativa tenía 20 tablas, 8 migraciones, 1 usuario/administrador activo, 4 roles, 44 permisos, 2 categorías, 2 productos, 1 caja y **1 sesión de caja existente**; Sales no tenía ventas, líneas ni mesas. Las huellas privadas iniciales permiten comprobar conservación sin publicar credenciales o datos de identidad.

## B. Arquitectura y cambios

Core expone `IdempotentOperations`, `OperationIntent`, `OperationResult`, `OperationStatus` y `OperationRejected`. `DurableOperations` coordina el protocolo; `SqlOperationStore`, `SqlOperationLock` y `SqlTransientErrors` implementan persistencia, exclusión y clasificación SQL. Se añade el controlador de consulta y el comando de compactación. Core no importa Users ni Sales.

Sales implementa `DurableSalesWrites` sobre su contrato `CriticalSalesWrites` existente y serializa exclusivamente sus DTOs públicos mediante `SaleSnapshotCodec`. Los servicios actuales siguen realizando negocio, revisión, validación y auditoría. Los controladores y Livewire delegan. La autorización se inyecta como callback y se verifica antes de consultar resultados y nuevamente bajo la coordinación de identidad.

Se modificaron proveedores, Request/Controller/POS de Sales, catálogo de códigos y traducciones de Core, rutas Core/console, OpenAPI, validador y fixtures/pruebas. Cash y Catalog productivos no se modifican. Se incorpora una sola migración permanente: `Modules/Core/database/migrations/2026_11_17_000009_create_idempotency_records.php`. Los workers, sondas, tablas auxiliares y usuarios ficticios de pruebas son exclusivos de `fresapos_test`.

## C. Claves, hashing y estados

UUID v4 con variante RFC admitida, normalizado a minúsculas. La API rechaza clave ausente o inválida con 400; no fabrica claves sustitutivas. La unicidad SQL es `(owner_context, idempotency_key)`, de modo que dos usuarios no comparten resultados aunque reutilicen un UUID.

`owner_context` es SHA-256 del contexto estable del actor autenticado. Es un espacio opaco de autorización, no una FK a Users: evita una dependencia funcional de Core. `resource_id` es una referencia genérica; las FK reales de Sales/Cash permanecen en sus tablas de negocio. No se deduce autorización de estos campos.

El hash SHA-256 incluye operación, método, recurso y parámetros canónicos: claves asociativas ordenadas, listas y tipos conservados, sin objetos, recursos ni flotantes. Sales normaliza ULID y textos Unicode según sus reglas existentes; nulo/vacío/ausente equivalen cuando el negocio así lo define. Cantidad, producto, mesa, nota significativa y revisión If-Match forman parte de la intención. Un cambio real requiere otro UUID. If-Match distinto con el mismo UUID es conflicto; un replay idéntico conserva la revisión original y devuelve el DTO confirmado, aunque la venta haya cambiado después.

Estados SQL: pending, completed y failed. Se almacena estado HTTP funcional y JSON saneado; no modelos, cabeceras, sesión, token, consulta SQL ni excepción. El identificador de petición del sobre HTTP se genera en cada respuesta; el resultado funcional sí se reproduce.

## D. Transacciones y recuperación

El coordinador exige ser la transacción externa. Obtiene un `sp_getapplock` exclusivo de sesión SQL por contexto/UUID, con timeout cero; dentro de TransactionManager autoriza de nuevo, consulta el recibo, inserta pending, ejecuta negocio y marca completed. **Negocio y recibo confirmado se comprometen juntos**. Los repositorios no abren transacciones independientes.

Pending no se confirma en una transacción separada. Mientras el proceso trabaja, el lock de sesión permite consultar pending sin leer su fila no confirmada ni bloquearse. Un rollback o la muerte del proceso elimina tanto pending como negocio; otro intento puede volver a ejecutar bajo exclusión después de comprobar ausencia del recibo. Un pending persistido manualmente o por otro protocolo se considera incierto y no se roba ni se limpia automáticamente.

Un rechazo definitivo saneado (validación de negocio, recurso ausente o conflicto) se guarda como failed solamente después del rollback del negocio. Autorización, indisponibilidad de Cash y errores internos no se convierten en fallos definitivos cacheados. Un fallo tras el commit no borra el resultado: el siguiente intento vuelve a consultar SQL antes de ejecutar. El protocolo no se basa en memoria, cookies, disabled o flags de interfaz.

## E. Concurrencia y reintentos

Misma clave mientras el lock está activo: 409 `OPERATION_IN_PROGRESS`; después del commit, mismo hash reproduce resultado y hash diferente devuelve 409 `IDEMPOTENCY_KEY_CONFLICT`. Otra clave para una mesa ocupada devuelve el conflicto funcional y no duplica ocupación.

Como máximo cinco intentos totales, con esperas de 100, 200, 400 y 800 ms. Solo errores SQL transitorios reconocidos (deadlock, timeout de lock, fallos de conexión y determinados códigos de servicio/throttling). Se reconecta únicamente cuando corresponde; siempre se reclama exclusión y se consulta el recibo antes del negocio. No hay reintento automático de validación, autorización ni conflictos funcionales. Los diagnósticos contienen número de intento/código SQL, sin SQL ni parámetros.

Pruebas con procesos HTTP y SPID independientes cubren claves iguales/diferentes concurrentes, agregado duplicado, mesa compartida, rollback, respuesta perdida, muerte antes del commit y excepción después del commit real. Una prueba provoca un deadlock SQL **1205 real**: dos operaciones se confirman, una con dos intentos; ambas filas de sonda se incrementan exactamente dos veces. No se simula ese resultado con SQLite o un array.

## F. Vigencia y limpieza

`core.idempotency.retention_hours` vale 24 y es configurable (rango defensivo 1–8760). La vigencia comienza al terminar la operación. `operations:compact` elimina **solo el cuerpo** de resultados terminales vencidos mediante UPDATE TOP por lotes, READPAST y READCOMMITTEDLOCK; máximo 500 filas por ejecución. Nunca borra claves, hashes, estado o referencias ni toca pending.

La clave histórica permanece indefinidamente para impedir que un UUID vencido vuelva a producir efectos. Un POST vencido devuelve conflicto; la consulta devuelve `state: expired` y metadatos seguros para reconciliar. 404/expired no autorizan al cliente a crear automáticamente otra intención. La retención histórica mínima tiene coste de almacenamiento que debe monitorizarse; no se añade una purga insegura.

Scheduler registra compactación horaria con `withoutOverlapping`. `artisan schedule:list` confirmó el registro horario. El despliegue debe ejecutar `php artisan schedule:run` cada minuto mediante cron o Programador de tareas; esta entrega no instala tareas del sistema. Sin ejecución del scheduler la protección sigue vigente; solo se conserva el cuerpo más tiempo.

## G. Integración Sales

Habilitados bajo la nueva coordinación: apertura de mostrador, apertura de mesa, agregado/consolidación de producto, cantidad absoluta, nota de línea, nota general, pausa y recuperación. El histórico de precio y cálculo decimal siguen perteneciendo a Sales. Cada intención produce a lo sumo un cambio y su auditoría.

No se reconstruye Sales. La configuración administrativa de mesas conserva su unicidad y revisión existentes; no se extiende este protocolo a un CRUD ajeno al alcance. Cancelación y retiro de línea conservan el bloqueo por motivos autorizados pendientes. Cobros, descuentos, cierre, tickets y folio definitivo no se habilitan.

## H. Integración Cash

Se utiliza la implementación real de `CashSessionAccess` y su validación en la transacción de apertura de Sales. No se altera el mecanismo durable de apertura de caja de 1.5d.1 ni se crea otra caja o sesión operativa. Abrir sin sesión activa devuelve indisponibilidad y no deja venta/recibo definitivo; puede reintentarse con la misma clave tras abrir caja. Nuevas escrituras siguen las reglas existentes de Cash; reproducir una operación ya confirmada no representa abrir otra venta y puede consultar el resultado después de cerrar su contexto.

## I. API REST v1

Las operaciones Sales protegidas exigen `Idempotency-Key` UUID v4; las mutaciones también conservan `If-Match`. Los sobres uniformes existentes permanecen intactos. Nuevos códigos 409: `OPERATION_IN_PROGRESS` y `IDEMPOTENCY_KEY_CONFLICT`.

`GET /api/v1/operations/{idempotencyKey}/status`, con autenticación y acceso API vigente, devuelve pending/completed/failed/expired y operación, referencia, estado HTTP y fecha terminal cuando corresponden. No devuelve body almacenado, hash, contexto de propietario ni identidad interna. Usuario ajeno y clave inexistente tienen la misma respuesta 404. Credenciales revocadas o permisos actuales insuficientes bloquean replay antes de revelar su resultado. El estado de progreso no habilita la operación sin sus propios permisos.

OpenAPI documenta cabeceras, patrón UUID4, estados, errores, seguridad y endpoint. El validador formal contrasta rutas exportadas del código y respuestas efectivamente obtenidas por pruebas.

## J. Livewire y continuidad

`PosIntentRegistry` mantiene en sesión **del servidor**, separado por actor, únicamente UUIDs, selección y última referencia. Guarda esa metadata antes del negocio; SQL sigue siendo la fuente de verdad. No se guardan carritos, importes, notas, contraseñas o tokens en localStorage/sessionStorage.

Un UUID representa una intención; el mismo snapshot/reintento conserva su clave. Las nuevas acciones explícitas generan UUID4 nuevos después de resolver cualquier pending. Un solo cambio financiero se admite por paquete de llamadas del componente. Los controles disabled son solo UX; replay HTTP directo y snapshots antiguos están cubiertos por SQL. Cambiar venta/modo/mesa reconoce la intención anterior y evita recuperarla involuntariamente al recargar.

El montaje y Actualizar vista consultan el estado durable y recuperan la venta sin ejecutar otra apertura/agregado. Se conservan las notas aún no guardadas de una venta seleccionada. Mientras pending se muestra el aviso y la consulta de estado; no se sustituye su clave. Se prohíbe renovar automáticamente un UUID incierto.

Revisión en Chrome real y base aislada: abrir caja con cero, abrir mostrador, descartar una respuesta Livewire después del commit y recuperar por recarga, agregar 2 unidades a 10.1234, ajustar a 3 (30.3702), guardar nota, pausar/recuperar y abrir mesa. SQL mostró dos ventas, una línea y siete recibos completed, con un evento por cambio. Se tomaron 22 capturas con controles visibles de al menos 44 px y sin desbordamiento/errores JS registrados en los cuatro tamaños. Evidencia y límites: [QA](qa/1.5d.2a/README.md). La pérdida de respuesta se provocó mediante Chrome Fetch después de recibir HTTP 200; no se afirma haber cortado físicamente la red SQL.

## K. Contrato futuro Android

No se implementa Android. Un futuro cliente genera UUID4 por intención, conserva su clave hasta conocer el resultado y reenvía los mismos parámetros/precondición. Ante timeout consulta status autenticado; pending exige esperar, completed/failed exige reconciliar, expired exige consultar la referencia segura, 404 no justifica duplicar automáticamente una escritura incierta. Distinta intención requiere otra clave. El usuario actual y permisos se revalidan; el UUID no es una credencial. No se permite enviar actor, precios o totales calculados por el cliente.

## L. Seguridad

Contexto derivado del actor real, no del body. Identidad y permisos actuales antes del replay; no hay bypass de administrador. Respuestas saneadas tanto con APP_DEBUG activo como inactivo. No se amplía el manejo JSON a vistas web/Livewire. .env, conexión Windows, configuración sensible y administrador se conservan mediante comparación de huellas. Ningún usuario de pruebas ni token se crea en fresapos.

## M. Auditoría

Se reutiliza auditoría transaccional de Sales. Rollback de auditoría revierte negocio y recibo; replay no emite otro evento financiero. El nuevo evento cerrado `idempotency.key_conflict` registra intento bloqueado y referencia interna, sin cuerpo, clave, hash o secretos. Las pruebas comparan conteos antes/después y verifican un solo efecto.

## N. SQL Server, migración e integridad

SQL Server usa ULID nchar(26), UUID native uniqueidentifier y checks v4/variante, estados coherentes, HTTP terminal y JSON válido; índice único de propietario/clave e índice de limpieza. Sin tablas permanentes auxiliares de negocio ni rowversion. La migración no actualiza registros históricos y su down se restringe a una base con sufijo `_test`.

La migración se aplica a fresapos únicamente después de pruebas, backup COPY_ONLY con CHECKSUM fuera del repositorio y RESTORE VERIFYONLY. La verificación compara 19 tablas de datos previas, las 8 migraciones originales y diez archivos protegidos. Los resultados finales y el respaldo se consignan en el cierre de validación de este informe. Los ensayos destructivos y la revisión visual se limitaron a fresapos_test, protegida por su nombre; fixtures eliminados antes de ejecutar la suite final.

Compatibilidad Azure SQL: T-SQL y tipos están preparados, pero no se conectó sin credenciales autorizadas. Pendientes reales: permisos sp_getapplock, RCSI/carga/throttling, recuperación de red del proveedor, pdo_sqlsrv, ODBC, cifrado/certificados, firewall y cron de cPanel/hosting. El deadlock y fallos tras commit se ejecutaron localmente; no se atribuyen resultados a Azure.

## O. Calidad y resultados

Los comandos se ejecutan con el PHP local mediante `scripts/local.cmd`; logs completos privados bajo `.tools/idempotency-1.5d2a-*`, sin publicar salidas con datos sensibles. Resultados finales detallados al final de este documento. La suite añade 32 casos a los 423 existentes; los fixtures históricos ahora usan UUID4 y siguen comprobando sus reglas originales con dobles explícitos cuando corresponde. Los nuevos tests de idempotencia usan el coordinador SQL real.

Durante el desarrollo fallaron intentos de test por Mockery no instalado, captura de contador por valor, expectativa de revisión incompleta y selección parcial de sesión; se corrigió código/fixtures y se reejecutaron los grupos. No se añadió Mockery ni se ocultaron errores. El arranque del motor Windows falló dos veces; se completó la revisión con Chrome headless real. Dos scripts auxiliares de QA fallaron por BOM/codificación de consola y se corrigieron antes de continuar. El cierre distingue esos fallos intermedios de los resultados finales.

## P. Pendientes y límites

Permanecen bloqueados cobros, pagos, cierre financiero, folio definitivo, descuentos especiales, impresión de tickets, arqueos y cierre de caja. Retiro/cancelación requieren motivos autorizados. No hay inventario, recetas, Android ni sincronización. Ninguna dependencia extra, Docker, commit o push. No se avanza a 1.5d.2b.

No se verificó Azure/cPanel ni una desconexión física SQL en el instante exacto del commit; sí se probaron commit real seguido de error, descarte de respuesta en navegador, proceso muerto antes de commit y deadlock real. El estado pending persistido fuera de este protocolo requiere diagnóstico manual; nunca se ejecuta de nuevo por simple vencimiento. El scheduler del sistema queda por configurar al desplegar.

## Cierre de validación ejecutada

| Verificación | Comando real (PowerShell, desde el repositorio) | Resultado |
| --- | --- | --- |
| Línea base | `.\scripts\local.cmd composer test` | 423 pruebas / 4091 aserciones; 7:41.868; 122 MB; exit 0 |
| Suite final | `.\scripts\local.cmd composer test` | **455 pruebas / 4653 aserciones; 10:31.762; 128 MB; exit 0** |
| Formato final | `.\scripts\local.cmd composer lint` | Pint passed; exit 0 |
| Análisis final | `.\scripts\local.cmd composer analyse` | Larastan nivel 5, 296 archivos, sin errores; exit 0 |
| Composer | `.\scripts\local.cmd composer validate --strict` | composer.json válido; exit 0 |
| Plataforma | `.\scripts\local.cmd composer check-platform-reqs` | Todos los requisitos instalados satisfechos; exit 0 |
| OpenAPI y respuestas | `powershell.exe -NoProfile -ExecutionPolicy Bypass -File scripts/validate-openapi.ps1 -Responses .tools/idempotency-1.5d2a-all-responses.json -Report .tools/idempotency-1.5d2a-openapi-final.json` | 46 operaciones; 215 respuestas reales de 36 operaciones; **0 errores / 0 advertencias**, exit 0 |
| Scheduler | `.\scripts\local.cmd php artisan schedule:list` | operations:compact cada hora, sin instalar tarea del sistema |
| Migración incremental | `.\scripts\local.cmd php artisan migrate --path=Modules/Core/database/migrations/2026_11_17_000009_create_idempotency_records.php --no-interaction` | DONE, 64.99 ms; exit 0 |
| Cambios de texto | `git diff --check` | Sin errores de whitespace; avisos Git de normalización CRLF/LF en documentación |

Se preservan los 423 casos previos y se añaden 32; crecen 562 aserciones. La suite tarda 2:49.894 más y utiliza 6 MB adicionales: incluye más esquemas de fixture y procesos SQL/HTTP reales. No se declara una mejora de tiempo. La configuración del timeout de Composer a 900 segundos se limitó al proceso de validación y se restauró en finally; composer.json no cambió.

**Respaldo antes de migrar:** `fresapos_pre_1_5d2a_20261117_174325Z_45551397.bak`, fuera del repositorio, 7,946,240 bytes, COPY_ONLY/CHECKSUM, `RESTORE VERIFYONLY ... WITH CHECKSUM` completado el 17/11/2026 a las 17:43:25 UTC. No se restauró la base ni se sobrescribió un respaldo anterior. La ubicación exacta permanece en la evidencia privada local.

**Integridad posterior:** 21 tablas operativas (20 originales más idempotency_records), 0 recibos operativos creados por pruebas. Las 19 tablas anteriores de datos tienen las mismas huellas y conteos; las ocho filas anteriores de migrations también conservan su huella. Se conservan 1 usuario y administrador activo, 4 roles, 44 permisos, 2 categorías, 2 productos, 1 caja y **1 sesión de caja previa**. Siguen existiendo 0 ventas, 0 líneas y 0 mesas operativas. Diez archivos protegidos coinciden byte a byte, incluyendo .env y Composer. La FK de Sales a Cash sigue habilitada y confiable. Los tres checks de la nueva tabla están habilitados y confiables; UUID es uniqueidentifier y el ULID es nchar(26), confirmado en sys.columns. [Reporte de integridad](qa/1.5d.2a/integrity-report.json), [esquema real](qa/1.5d.2a/schema-report.json).

**HTTP operativo posterior:** GET /, /up, /api/v1/ping, /login y el JavaScript real de Livewire devolvieron 200. /pos, /cash y /admin/catalog/products devolvieron 302 hacia autenticación; la consulta de estado sin autenticar devolvió 401. No se utilizó el administrador operativo para ensayos autenticados.

**QA adicional real:** en una segunda siembra aislada se enviaron dos clics nativos consecutivos al abrir y al agregar: SQL confirmó una venta, una línea de cantidad 2 y un solo evento por operación. Chrome con latencia de 1500 ms mostró una petición pendiente y diez controles deshabilitados. Un cambio externo de nota mediante el servicio real produjo revisión desactualizada: la escritura del navegador quedó failed/409 sin sobrescribir la nota del servidor; Actualizar vista conservó el borrador del usuario. Cuatro capturas adicionales completan **22 capturas**. Los fixtures se eliminaron otra vez: fresapos_test terminó con cero tablas de usuario. Ambos servidores de QA y las sesiones Chrome se cerraron; el servidor operativo siguió activo.

**Límites de la evidencia:** las 46 operaciones tienen validación formal y de rutas; hay respuestas capturadas para 36, no se inventan muestras para las diez restantes. Los grupos específicos y la suite final pasaron; fallos intermedios están consignados en O. No se conectó a Azure ni a cPanel. No se configura cron/Programador de tareas sin un despliegue definido. Las fotos muestran el desplazamiento de cada escenario, no siempre la cabecera y la alerta simultáneamente.

## Informe final solicitado (15 puntos)

1. **Diagnóstico inicial:** 423/4091 en verde; Sales tenía bloqueos por idempotencia y Cash real ya disponible.
2. **Implementación:** contratos y coordinador Core reusable, adaptador Sales, migración única, estado autenticado y compactación.
3. **Idempotencia:** UUID4, contexto propietario, hash canónico, pending/completed/failed y referencias permanentes tras TTL.
4. **Concurrencia/recuperación:** appLock SQL, ACID, máximo cinco intentos selectivos; ocho carreras HTTP, muerte del proceso, fallo posterior a commit y deadlock 1205 reales.
5. **Sales:** servicios existentes, instantáneas históricas y revisión conservados; no se reconstruyó el módulo.
6. **Cash:** contrato real reutilizado sin modificar su apertura; se conserva la sesión operativa previa.
7. **API/Livewire:** cabecera obligatoria, códigos 409, status con aislamiento, claves por intención y recuperación sin duplicar.
8. **Seguridad/auditoría:** permisos actuales antes del replay, saneamiento, aislamiento y un evento por efecto.
9. **SQL/migraciones:** backup verificado y una migración incremental aplicada; checks e índices verificados en SQL Express.
10. **Calidad:** 455/4653, Pint, Larastan 5, Composer y OpenAPI en verde; revisión Chrome real en cuatro resoluciones.
11. **Integridad:** 19 tablas de datos y ocho migraciones previas intactas; diez archivos protegidos idénticos; sin datos ficticios operativos.
12. **Habilitado:** aperturas de mostrador/mesa, agregado, cantidad absoluta, notas, pausa y recuperación con dependencias/permisos satisfechos.
13. **Bloqueado:** cobros, cierre, folios, tickets, descuentos especiales, retiro/cancelación sin motivos autorizados y operaciones financieras futuras.
14. **Pendientes:** Azure/cPanel, scheduler del sistema y ensayos físicos de pérdida SQL en el instante del commit; no bloquean la aceptación local documentada.
15. **Conclusión:** **ETAPA 1.5d.2a: ACEPTADA LOCALMENTE**. Sin commits/pushes y sin avanzar a 1.5d.2b. No hay decisiones adicionales que requieran confirmación para esta entrega local.
