# Entrega 1.5d.4 — Arqueo ciego y cierre seguro de caja

18 de noviembre de 2026, America/Mexico_City. **ETAPA 1.5d.4: ACEPTADA LOCALMENTE**. Arqueo/cierre, regresión, concurrencia SQL, OpenAPI, revisión Chrome, respaldo verificado, migraciones incrementales e integridad comprobados. Azure SQL/cPanel conservan su validación pendiente propia.

## A. Estado inicial

Rama main, HEAD inicial `32cd2fd`, árbol limpio. PHP 8.3.36, Laravel 13.42.0, Livewire 4.4.8, Sanctum 4.4.0 y SQL Server Express AUDITORIA 17.0.1155.3; autenticación integrada Windows. Se inspeccionaron informes 1.5a/b/c y 1.5d.1/2a/2b/3, contratos, esquema, modelos, servicios, autorizaciones, API, Web, idempotencia, auditoría y workers concurrentes.

Línea base completa: **547 pruebas / 5831 aserciones, 10:22.947, 152 MB**, sin fallos. Pint correcto, Larastan nivel 5 con 334 archivos sin errores, Composer validate --strict y check-platform-reqs correctos. La primera ejecución de Composer agotó su límite predeterminado de 300 segundos; no cuenta como baseline aprobada. Se detuvo una repetición iniciada antes de completar limpieza; la limpieza inicial encontró una restricción de fixture no instalada. Se retiró solo el esquema realmente existente en fresapos_test, se comprobó cero tablas y se repitió la suite completa con COMPOSER_PROCESS_TIMEOUT=1800 solo del proceso.

Se capturaron huellas de las 25 tablas operativas y diez archivos protegidos. Ya había dos ventas, tres líneas, dos pagos confirmados, un contador diario, dos movimientos y una sesión Cash. Es historia operativa, no fixtures: no se modificó, cobró o canceló para preparar pruebas. Los escenarios y cierres de QA son exclusivos de fresapos_test, con TestDatabaseGuard y configuración sin conexión operativa. SQL y QA visual se ejecutan secuencialmente.

Cash ya tenía cierre conceptual (status, closed_by, closed_at), unicidad de sesión abierta por caja, saldo exacto con efectivo neto de Sales y protocolos de bloqueo; faltaban caso de uso, arqueo persistente, consulta histórica y transporte. Se reutilizan, sin reconstrucción ni dependencias Composer nuevas.

## B. Modelo de arqueo

CashReconciliation/cash_reconciliations: ULID nchar(26), cash_session_id único con FK Cash, counted_by con FK Users, counted_cash/expected_cash/difference decimal(19,4), observation nvarchar(200), confirmed_at UTC, summary JSON y timestamps. Las FK no eliminan en cascada. Índices por fecha/ID y actor/fecha.

La diferencia conserva el signo y la CHECK exige diferencia=contado−esperado, importes no negativos, JSON válido y observación si existe diferencia. Un trigger rechaza UPDATE/DELETE y el modelo también impide cambios/borrado. Una sesión solo tiene un arqueo final; no se inventan estados de borrador persistidos. El JSON guarda un resumen histórico permitido, no modelos ni secretos, y se devuelve sin recalcularlo con precios o nombres actuales.

## C. Arqueo ciego

Inicio consulta únicamente identificadores/tipo/estado/mesa de ventas pendientes, paginados de 25. No cotiza ni devuelve saldo, diferencias o indicadores financieros. Summary y reconciliation de una sesión abierta responden 409 sin importes. Confirmar envía únicamente counted_cash y observation, además del UUID4 en cabecera; actor, fechas, estado, saldo y diferencia proceden del servidor.

La observación breve se exige **en todos los conteos**, incluida caja cuadrada. Así, el error por observación ausente es idéntico para cualquier importe y no funciona como oráculo para tantear el saldo esperado. Sin tolerancias, redondeos, aprobación especial inventada o ajuste automático. La comprobación no revela si el conteo habría producido diferencia.

El formulario Livewire no incluye el saldo teórico en su snapshot. Al iniciar el conteo se retira el panel de movimientos y el fondo inicial de la vista operativa. Los permisos existentes siguen permitiendo consultas financieras previas fuera del flujo ciego; no se promete impedir que un usuario con autorización financiera recuerde o consulte esas cifras. Los cierres anteriores sí son consultables con cash.closings.view y se identifican por sesión.

## D. Conciliación

FinancialStore::balance es la única fórmula: fondo inicial + cash_in − cash_out + efectivo neto confirmado de Sales. ConfirmedSalesCash existente aporta applied_amount cash, ya neto de cambio, de pagos confirmed y ventas closed. No se crea una entrada manual por cobro. Tarjetas, transferencias y other no son efectivo; preparados/pendientes no aportan ingresos.

ClosingInput::difference delega la resta de magnitudes a DecimalAmount existente y conserva el signo como texto. Sin float, BCMath adicional o entero escalado que desborde PHP. Una diferencia negativa no se convierte a cero. No se modifican pagos para cuadrar.

FinancialStore normaliza también el fondo de entrada mediante su conversión decimal existente: SQL puede devolver cero como `.0000` en consultas históricas por Query Builder. La salida, incluida una sesión cerrada con fondo cero, conserva `0.0000`. No cambia la fórmula ni se agrega otro cálculo de saldo.

## E. Reglas de cierre

CloseCashSession implementa CashClosings. Autoriza identidad/permisos actuales, valida conteo/observación, construye OperationIntent, adquiere exclusión durable, entra en transacción Core y reautoriza bajo lock de identidad. Bloquea caja y sesión; exige estado open; comprueba ventas pendientes; obtiene saldo y resumen Sales; fija UTC; inserta arqueo/resumen; cambia sesión a closed; audita; completa recibo Core y commit.

Un fallo de negocio/auditoría revierte arqueo, estado, auditorías y recibo pendiente juntos. No hay arqueo final confirmado por separado. La sesión cerrada no se reabre. El índice filtrado existente libera la caja para una apertura explícita con otro ULID y fondo propio; no hay traslado automático ni apertura implícita, ni se confunde con el cierre del día comercial.

## F. Ventas pendientes

SessionSales es un puerto consumidor definido por Cash e implementado por SessionSalesSummary dentro de Sales. Cash no importa SQL ni modelos privados de Sales. El puerto exige transacción externa. Detecta open/paused de mostrador o mesa, incluso sin líneas. Cualquier pendiente bloquea el cierre completo, sin cancelar o reasignar ventas. Los identificadores solo se muestran con sales.view; enlace al POS existente. Motivos de cancelación todavía no implementados conservan su bloqueo; no se inventan para liberar la caja.

## G. Concurrencia

Se conserva orden global identidad → caja → sesión → escritura de Sales/Cash. UPDLOCK/HOLDLOCK permanecen hasta commit externo. El cierre revalida dentro de la transacción; ventas, checkout, cantidades/notas y movimientos ya usan la misma coordinación. La serialización conservadora evita actividad financiera posterior al cierre; no se afirma capacidad de carga medida.

Además, triggers SQL rechazan movimientos en sesión cerrada, alta/cambio de ventas, líneas o pagos vinculados a sesiones cerradas y modificación/borrado de sesiones cerradas. Cerrar por SQL sin un arqueo coincidente en sesión/actor/fecha se rechaza. Los triggers usan locks de sesión; los errores de deadlock recuperables siguen perteneciendo al coordinador Core. El servicio es quien garantiza la unidad ACID del proceso completo; no se presenta una FK o CHECK como validación de agregados entre tablas.

Doce carreras nuevas usan procesos PHP/HTTP y SPID SQL independientes: claves iguales/distintas, otro actor, movimiento antes/después, checkout previo, apertura de venta posterior, edición real de cantidad, respuesta perdida, rollback y deadlock. Se comprueba espera real LCK_M_ mediante DMV. El deadlock usa una inversión de locks solo en fixture y confirma código 1205/reintento del cierre productivo. No se simula con SQLite.

## H. Idempotencia

Se reutiliza IdempotentOperations: UUID v4, contexto del actor, hash canónico de sesión/conteo normalizado/observación, sp_getapplock y recibo durable. Mismo hash reproduce resultado histórico; distinta carga misma clave produce conflicto; otra clave sobre sesión cerrada no crea otro arqueo. Otra identidad no adquiere el recibo del propietario por conocer la clave.

La Web guarda únicamente metadata de intención en sesión del servidor (actor derivado, sesión, clave y estado de envío); no saldos, carritos o credenciales en almacenamiento del navegador. Guarda metadata antes del negocio. Pending mantiene UUID; not_found después de envío no autoriza automáticamente otra intención. Failed permite preparar otra explícitamente. Tras commit perdido, consulta estado Core y recupera arqueo por sesión. Si el cuerpo Core venció, la referencia permite consultar el cierre con autorización actual; no se reejecuta el negocio. Un solo cierre por paquete Livewire y locks SQL protegen dobles clics y snapshots firmados antiguos.

## I. Seguridad

Sin cambios de roles, permisos o credenciales. Consulta de historial/resumen exige cash.view + cash.closings.view; cierre exige además cash.close. Listar identificadores pendientes exige también sales.view. Es la caja compartida según la política ya existente, sin inventar propiedad por cajero. Cajero/supervisor/admin tienen permisos explícitos existentes; mesero no recibe acceso financiero. No hay bypass admin.

API mantiene Sanctum bearer y auth.access.api: usuario activo, token/sesión vigentes y contraseña cambiada. Web mantiene cookies/CSRF, CashAccess persistente y revalidación de actor por acción. Actor/contexto/clave/sesión/recibo son Locked. Un cambio de empleado por PIN invalida snapshots del empleado anterior. Campos desconocidos se rechazan. SensitiveErrors añade únicamente cash.closing; API conserva el saneamiento Core con debug activo e inactivo.

## J. Auditoría

SecurityEvent incorpora CashClosingAudit cerrado y validado. El listener Audit existente guarda los eventos cash.reconciliation.confirmed y cash.session.closed; si hay diferencia, cash.difference.detected. Incluye actor, referencias de sesión/caja/arqueo, fecha UTC, esperado/contado/diferencia; no guarda UUID, hash, PIN, credenciales o texto libre de observación. El resultado completo se consulta en Cash con sus permisos.

Eventos y recibo se confirman con el cierre; replay no duplica auditoría. Rechazos definitivos quedan como recibos failed de Core; conflictos de clave utilizan el evento central idempotency.key_conflict existente. No se duplican eventos en controladores/Livewire ni se crea otro almacén de auditoría.

## K. API

Prefijo /api/v1/cash/sessions:

| Método/ruta | Contrato |
| --- | --- |
| GET /{id}/pending-sales | IDs operativos paginados; sin importes. |
| POST /{id}/close | counted_cash texto exacto, observation y cabecera Idempotency-Key UUID4. 200 inicial/replay. |
| GET /{id}/summary | Resumen histórico; 409 antes del cierre. |
| GET /{id}/reconciliation | Mismo resultado histórico permitido. |
| GET /history | Páginas 10/25/50/100, fecha UTC de cierre, opened_by, closed_by, register_id y status. |

La ruta history se registra antes de las rutas con ULID. Sobres, no-store y request_id existentes. ULID/importes se serializan como texto; fechas ISO UTC. 400/401/403/404/409/422/429/500/503 saneados. No acepta esperado, diferencia, actor, fecha o estado desde cliente. status=open en el historial de cierres produce colección vacía, sin precalcular sesiones abiertas.

## L. Interfaz Web

Panel CashClosingPanel dentro de /cash, Blade/Livewire y estilo existente, sin reconstruir CashDesk. Inicio, bloqueo por pendientes, captura total decimal, observación, confirmación explícita irreversible, consulta de estado, recibo, diferencias e historial. No se añaden denominaciones configurables. Botones de 44 px, labels, alertas, estado de carga/offline y disabled como UX; la seguridad real permanece en servidor.

CashDesk escucha el cierre para refrescar el estado y preparar una nueva clave de apertura; no reutiliza la intención antigua de la sesión cerrada. La nueva apertura sigue siendo expresa. Consultar un cierre histórico no cambia la intención de cierre activa.

## M. Historial y resumen

ClosingStore pagina y filtra en SQL, orden por fecha/ID descendentes; no carga todas las sesiones en memoria. Resumen legible: caja/sesión, apertura/actor/fondo, cierre/actor/fecha, ventas cobradas/total, pagos agrupados por ID/código histórico, efectivo neto, entradas/salidas, esperado/contado/diferencia/resultado y observación. No confunde ventas con movimientos. Cero ventas/pagos es válido para un turno sin operación. No hay PDF, Excel o motor de reportes paralelo.

## N. SQL Server y respaldo

Migraciones incrementales 000013_create_cash_reconciliations (Cash) y 000014_guard_closed_cash_sessions (Sales). La segunda conserva en Sales la propiedad de sus barreras SQL. No reconstruyen CashSession ni reescriben filas históricas. Down solo admite nombres terminados en _test y sirve para retirar fixtures; nunca rollback operativo.

Respaldo real **fresapos_pre_1_5d4_20261118_154527Z_4dc514ae.bak**, **10 108 928 bytes**, fuera del repositorio, COPY_ONLY/CHECKSUM, sin sobrescritura. RESTORE VERIFYONLY WITH CHECKSUM, STOP_ON_ERROR completado a las **15:45:27 UTC** del 18 de noviembre. Metadata/ruta privada en .tools/reconciliation-backup.json; [evidencia sin ruta física](qa/1.5d.4/backup-report.json). VERIFYONLY no equivale a una restauración de ensayo; esta última no se ejecutó.

Después de la regresión y comprobación decimal focalizada se aplicaron únicamente 000013 (**117.41 ms**) y 000014 (**9.04 ms**), ambas **lote 12 Ran**. Quedaron catorce migraciones y 26 tablas. La tabla nueva permanece vacía; no hubo cierres operativos de demostración. Se verificaron dos FK nuevas confiables/NO ACTION y seis triggers nuevos habilitados. No se ejecutó rollback operativo, migrate:fresh, db:wipe o reconstrucción de tablas.

## O. Calidad

39 casos nuevos iniciales: **39 pruebas / 572 aserciones, 1:19.502**, sin fallos, incluidas doce carreras, API y Livewire nativo/PIN. Se añadieron dos casos para barreras SQL/preparados y paginación histórica y otro para una intención pending persistida fuera del protocolo: **42 casos nuevos** en total. Larastan verifica 348 archivos sin errores; Pint pasó tras la segunda pasada en tres archivos nuevos. Composer validate --strict y check-platform-reqs correctos. Los cuatro códigos de salida reales son 0 en .tools/reconciliation-quality-exit-codes.json.

Primera regresión completa: **588 pruebas / 6421 aserciones, 11:37.349, 162 MB; un fallo**, la lista exacta de rutas de ApiConventionsTest todavía no incluía los cinco nombres nuevos. Se corrigió sin retirar aserciones; las otras 587 pruebas pasaron. Repetición focalizada de ApiConventionsTest: **29/274**, correcta. El caso adicional pending persistido pasó **1/4** antes de la regresión final. No se presenta la primera regresión como suite aprobada.

Regresión completa aprobada: **589 pruebas / 6425 aserciones, 10:44.473, 164 MB, exit 0**, sin fallos, errores u omisiones. Frente a baseline: **+42 pruebas, +594 aserciones, +21.526 s y +12 MB**; se conservan los 547 casos anteriores. El último ajuste de normalización del fondo histórico cero se verificó después con **CashReconciliationTest: 24/146, 37.632 s, 68 MB** y **CashFinanceTest: 6/47, 7.516 s, 60 MB**, ambos exit 0. Son repeticiones focalizadas, no casos adicionales que se sumen artificialmente a las 589 pruebas de la suite. El contador 6425 corresponde a la ejecución completa registrada; la nueva aserción específica de cero histórico consta en la repetición focalizada.

| Comando desde PowerShell en la raíz | Resultado real |
| --- | --- |
| `.\scripts\local.cmd composer test` | 589/6425, exit 0; timeout 1800 solo del proceso, restaurado al terminar |
| `.\scripts\local.cmd php vendor/phpunit/phpunit/phpunit --filter CashReconciliationTest` | 24/146, exit 0 tras normalización final |
| `.\scripts\local.cmd php vendor/phpunit/phpunit/phpunit --filter CashFinanceTest` | 6/47, exit 0 tras normalización final |
| `.\scripts\local.cmd composer lint` | Passed, exit 0 |
| `.\scripts\local.cmd composer analyse` | Nivel 5, 348 archivos, cero errores, exit 0 |
| `.\scripts\local.cmd composer validate --strict` | Válido, exit 0 |
| `.\scripts\local.cmd composer check-platform-reqs` | Requisitos satisfechos, exit 0 |
| `powershell.exe -NoProfile -ExecutionPolicy Bypass -File scripts/validate-openapi.ps1 -Responses .tools/reconciliation-all-responses.json -Report .tools/reconciliation-openapi-responses.json` | 57 operaciones, 315 respuestas reales, cero errores/advertencias |
| `.\scripts\local.cmd php artisan migrate --path=Modules/Cash/database/migrations/2026_11_18_000013_create_cash_reconciliations.php --path=Modules/Sales/database/migrations/2026_11_18_000014_guard_closed_cash_sessions.php --no-interaction` | Dos migraciones Ran, lote 12 |
| `.\scripts\local.cmd php artisan migrate:status` | Catorce migraciones Ran |
| `.\scripts\local.cmd php .tools/reconciliation-visual/empty-state.php` | Cero tablas de fixtures al terminar |
| `git diff --check` | Sin errores |

Logs privados con prefijo .tools/reconciliation-: baseline-tests-complete.log, tests-full.log (fallo intermedio), tests-final.log/exit.txt, decimal-regression.log, finance-regression.log, focused-exits.json, lint-final.log, analyse-final.log, composer-final.log, platform-final.log, migration.log/status.log y doce race-*.json. [Resumen seguro de calidad](qa/1.5d.4/test-results.json).

Fallos intermedios: timeout de Composer/fixtures interrumpidos descritos en A; expectativa demasiado específica del código SQL al borrar sesión (FK 547 protege antes del trigger); callback de autorización devolvía UserSnapshot donde el contrato exige void (corregido sin ignores); lista exacta de rutas de ApiConventionsTest actualizada para cinco nuevas rutas. No se eliminan pruebas ni se amplían tipos para silenciar errores.

## P. OpenAPI

Se añadieron cinco operaciones y cuatro esquemas sobre el documento actual, conservando los contratos anteriores. Validación formal: **57 operaciones**. Contraste final, repetido con respuestas exportadas por la regresión: **315 respuestas reales / 47 operaciones, cero errores y advertencias**. Las diez restantes conservan pruebas de regresión, pero no se afirman como muestras exportadas. Scripts y validador existentes, sin librerías productivas nuevas. [Informe formal](qa/1.5d.4/openapi-report.json).

## Q. Revisión visual

Ejecutada con Chrome **157.0.8103.79** real por CDP, perfil privado y servidor PHP aislado en 8084: **38 capturas únicas** en 1440×900, 1024×768, 768×1024 y 390×844. Sin desbordamiento, errores JavaScript o botones visibles menores de 44 px. Se consultó computer-use; no hay herramienta node_repl/Computer Use disponible en esta sesión, por lo que se utilizó Chrome instalado como alternativa.

Se verificaron tres ventas pendientes (mostrador abierta/pausada y mesa), formulario/snapshot sin saldo, Tab, doble clic con latencia, carga disabled, resultado cuadrado/faltante/sobrante, filtros/historial, respuesta HTTP 200 descartada después del commit y recuperación por recarga, permiso retirado y mesero sin acceso. SQL confirmó cinco cierres/arqueos, cinco auditorías de cada tipo, dos diferencias, tres pagos, dos movimientos y cero recibos pending. Una sexta sesión ficticia quedó abierta ante el rechazo por permiso retirado. La primera sesión: fondo 100 + entrada 30 − salida 10 + efectivo neto 180 = esperado 300; total vendido 540. Los otros métodos no aumentaron efectivo.

El primer helper de revocación utilizó el modelo Spatie estándar con IDs enteros; SQL rechazó su conversión a los ULID del proyecto. La revocación no ocurrió y el siguiente cierre de fixture fue autorizado, añadiendo el quinto cierre de QA. Se corrigió únicamente el helper aislado para los IDs reales y se repitió con otra sesión: cierre rechazado y sesión abierta. No se alteró Users productivo. La captura permission-ready se repitió; no se cuentan dos imágenes por el mismo archivo. [Capturas, evidencia SQL y límites](qa/1.5d.4/README.md).

Se cerraron solo los procesos Chrome/PHP propios de QA, se retiraron los catorce esquemas de fixture y se comprobó **cero tablas en fresapos_test** antes de la regresión final. No se cortó físicamente la red SQL ni se usaron sesiones operativas.

## R. Integridad

Comparación final: **24 tablas de datos previas intactas**, doce filas originales de migraciones intactas, **diez archivos protegidos idénticos** y un administrador activo conservado. Se mantuvieron la sesión operativa abierta, las dos ventas cerradas, sus tres líneas y dos pagos de efectivo, dos movimientos, folios/contador, idempotencia y auditoría. La tabla nueva de arqueos tiene **cero filas operativas**. No se restauraron datos para forzar igualdad. [Integridad](qa/1.5d.4/integrity-report.json) y [configuración protegida](qa/1.5d.4/protected-report.json).

Se inició el servidor local existente en **http://127.0.0.1:8080** con proceso oculto. Nueve comprobaciones HTTP reales: raíz/up/ping/login/JavaScript Livewire 200; POS/Cash anónimos 302; historial y resumen Cash sin bearer 401. Las pruebas autenticadas Web/API/PIN/historial y la revisión visual se hicieron con usuarios aislados, sin iniciar sesión con credenciales del administrador operativo. [Salud local](qa/1.5d.4/health-report.json).

No se modificaron .env, autenticación Windows, administrador, roles, permisos, Composer/configuración o almacenamiento protegidos. No se crearon fixtures, cobros o cierres en fresapos. Sin commits ni pushes. fresapos_test se verifica vacía al concluir y los procesos QA propios se detuvieron; 8080 permanece disponible para el usuario.

## S. Pendientes y alcance

Azure SQL/cPanel no verificados: permisos, sp_getapplock/DMV/triggers, carga/RCSI, conectividad/reintentos del proveedor, pdo_sqlsrv, ODBC, cifrado/certificados, firewall y scheduler requieren aceptación en su entorno autorizado. No se conectó a Azure sin credenciales ni se afirma compatibilidad de hosting sin probarla.

Permanecen fuera del alcance: autorización funcional de descuentos, motivos de retiro/cancelación, reembolsos/devoluciones, reapertura/corrección de ventas pagadas, división, inventario, recetas, Android, tickets, impresión y reportes PDF/Excel. No se avanza a 1.5d.5.

## Informe final solicitado

| Punto | Resultado |
| --- | --- |
| 1. Diagnóstico | Base previa aceptada y conservada; faltaban arqueo y cierre productivos. |
| 2. Modelo | CashReconciliation único por sesión, FK/decimales/UTC/JSON e inmutabilidad. |
| 3. Conteo ciego | Sin precálculo; observación siempre obligatoria y resultado solo después de confirmar. |
| 4. Diferencias | Magnitud exacta con signo; cuadrada, faltante y sobrante, sin ajuste automático. |
| 5. Cierre | Una transacción Core para arqueo, sesión, resumen, auditoría y recibo. |
| 6. Pendientes | Ventas open/paused bloquean; IDs autorizados y regreso al POS. |
| 7. Concurrencia | Doce carreras SQL independientes; espera real y 1205 recuperado. |
| 8. Idempotencia | UUID4 Core, replay, conflicto, pending y respuesta perdida. |
| 9. Seguridad | Matriz vigente, actor real, bearer/cookie/CSRF, PIN y reautorización. |
| 10. Auditoría | Un evento por arqueo/cierre; diferencia cuando corresponde, sin duplicados. |
| 11. API | Cinco operaciones nuevas, errores uniformes, respuestas permitidas. |
| 12. Livewire | Integrado en /cash, doble clic, carga, consulta de estado y recuperación. |
| 13. Historial | Paginado en SQL, filtros y resumen histórico inmutable. |
| 14. SQL Server | Respaldo verificado; 000013/14 lote 12, FK y seis triggers comprobados. |
| 15. Pruebas | 589/6425 completas; ajuste final focalizado 24/146 y 6/47; calidad exit 0. |
| 16. OpenAPI | 57 operaciones, 315 respuestas reales, cero errores/advertencias. |
| 17. Visual | Chrome real, 38 capturas únicas en cuatro resoluciones. |
| 18. Integridad | 24 tablas y diez archivos intactos; cero arqueos operativos de prueba. |
| 19. Habilitado | Arqueo/cierre, conciliación, consulta histórica y apertura explícita posterior. |
| 20. Pendientes | Azure/cPanel y funcionalidades posteriores indicadas en S; ninguna confirmación local pendiente. |
| 21. Conclusión | **ETAPA 1.5d.4: ACEPTADA LOCALMENTE**. Se detiene esta entrega. |
