# Entrega 1.5d.5 — Aceptación integral del ciclo financiero

18 de noviembre de 2026. **ETAPA 1.5d.5: ACEPTADA LOCALMENTE**. Ciclo financiero validado en SQL Server Express; datos operativos conservados y sin despliegues. Azure SQL/cPanel y activación del Scheduler quedan pendientes externos explícitos.

## A. Diagnóstico inicial

Inspección de solo lectura sobre `main`, HEAD `0d5fd56`, árbol limpio. Se revisaron informes 1.5a–1.5d.4, Composer, módulos, contratos Core, coordinación transaccional, idempotencia, Sales, Cash, Catalog, Auth/Users, Audit, rutas REST/Web, POS, migraciones, OpenAPI, permisos y pruebas concurrentes. Laravel 13.42.0, PHP 8.3.36, SQL Server Express 17.0.1155.3, instancia `.\AUDITORIA`, autenticación Windows. No se reconfigura el entorno.

Línea base ejecutada: **589 pruebas / 6426 aserciones, 13:32.342, 164 MB**, exit 0. Pint y Larastan nivel 5 correctos, exit 0. Las 6425 aserciones del informe anterior pertenecían a su ejecución completa; su comprobación final del fondo cero incorporó la aserción adicional que ahora ejecuta la suite.

Estado operativo inicial real: 26 tablas, 14 migraciones, un usuario/administrador, cuatro roles, 44 permisos, dos categorías, dos productos, una caja, **tres sesiones Cash (dos cerradas y una abierta), tres ventas cerradas, cuatro líneas, tres pagos, tres movimientos, dos arqueos y 17 recibos Core**. La actividad posterior a 1.5d.4 forma parte de la historia que debe conservarse; no se presupone una base vacía. Huella inicial privada por tabla y diez archivos protegidos.

No se identificó inicialmente una necesidad de modificar negocio, contratos o esquema. Riesgos a comprobar: integración entre turnos y folios; mezcla de efectivo y pagos no efectivos; dependencia accidental del catálogo actual; pérdida de respuesta; permisos revocados; compactación de recibos; consultas que crecen por fila; serialización global conservadora de identidad. Azure SQL, cPanel y ejecución automática del Scheduler no quedan demostrados por SQL Express.

## B. Matriz integral

Resultados contrastados contra la regresión final **593/6832**, las respuestas HTTP y la evidencia del navegador. Todos los datos de escritura son fixtures en `fresapos_test`. Los casos previos se ejecutaron en la línea base y volvieron a pasar en la regresión final; no son solamente resultados históricos.

| Requisito | Módulos | Datos de prueba | Resultado esperado | Prueba automatizada | Resultado real | Evidencia |
| --- | --- | --- | --- | --- | --- | --- |
| A: apertura | Auth/Users/Cash | Caja disponible, fondo 123.4567 | ULID/actor/UTC, apertura única | FinancialCycleTest; CashTest/HttpTest/ConcurrencyTest | Apertura y replay correctos; segunda clave rechazada | cycle.log; sql-evidence.json |
| B: mostrador | Sales/Catalog/Core | Producto 180, cantidad 2, nota, pausa | Conserva cantidad, nota e instantánea | FinancialCycleTest; SalesTest/HttpTest; PointOfSaleTest | Pausa/recuperación y precio histórico correctos tras cambiar/eliminar producto de fixture | cycle.log; counter-paused-*.png |
| C: mesa | Sales/Cash | Dos mesas API y una Web | Ocupación única y liberación al cobrar | FinancialCycleTest; SalesConcurrencyTest; CashConcurrencyTest | availability=free tras cierre; índice/carreras pasan en baseline | cycle.log; table-occupied-*.png |
| D–F: cobros | Sales/Cash/Audit/Core | Cash/card/transfer/other/mixed | Pagos atómicos y replay sin duplicados | FinancialCycleTest; CheckoutTest/HttpTest/LivewireTest/IntegrityTest | API: 1080 vendido, 440 efectivo neto, 60 cambio; Web: 900/260/40 | cycle.log; sql-evidence.json; *-receipt-*.png |
| G: folios | Sales/Core | Cinco cobros y siguiente turno | Secuencia global sin reinicio | FinancialCycleTest; CheckoutTest/ConcurrencyTest | API 202611170001–0006; medianoche y rollback pasan en baseline | cycle.log; checkout-1.5d3-race-*.json |
| H: movimientos | Cash/Core/Audit | +30.1234, −10.0001 | Dos movimientos, saldo exacto | FinancialCycleTest; CashFinanceTest/HttpTest/LivewireTest/ConcurrencyTest | API 583.5800; Web 380.0000, sin movimientos por ventas | cycle.log; cash-movements-*.png |
| I: arqueo | Cash/Sales | Cuadrado/faltante/sobrante/cero | Ciego hasta confirmar | FinancialCycleTest; CashReconciliationTest/HttpTest/LivewireTest | Resultado previo bloqueado; conteo Web oculta panel financiero; baseline conserva diferencias | blind-count-*.png; cycle.log |
| J: cierre | Cash/Sales/Core/Audit | Ventas pendientes/resueltas | Todo el cierre o rollback | FinancialCycleTest; CashReconciliationTest/ConcurrencyTest | Cinco pendientes bloquean; cierre posterior y replay únicos; carreras baseline correctas | cycle.log; reconciliation-race-*.json |
| K: nuevo turno | Cash/Sales | Fondo nuevo cero | ULID/arqueo distintos, aislamiento | FinancialCycleTest | Efectivo cero y tarjeta no lo aumenta; folio 0006 | cycle.log; second-turn-*.png; second-closing-*.png |
| L: recuperación | Core/Sales/Cash | UUID, fallos, procesos/SPID separados | Un efecto financiero | IdempotencyTest/ConcurrencyTest/DeadlockTest; carreras financieras | Baseline pasa; Chrome pierde HTTP 200 y recupera el mismo cobro | checkout-response-lost.png; mixed-recovered-*.png; races |
| Seguridad | Auth/Users/Core | Roles reales, token revocado | Rechazo sin efectos financieros | FinancialCycleTest; AuthenticationTest; AdvancedSecurityTest; HTTP/PIN | Revocación individual/global, contraseña/logout dan 401; baseline cubre Web/PIN/permisos | cycle.log; suite completa |
| SQL | Cash/Sales/Core | Base real solo lectura y fixtures | Sin referencias/agregados incoherentes | FinancialIntegrity; CLI de inspección | 21 comprobaciones, cero violaciones; 55 columnas ULID operativas | operative-invariants-before.json; cycle-integrity.json |
| API | Core/Auth/Catalog/Sales/Cash | Respuestas HTTP efectivas | Rutas = OpenAPI y JSON conforme | Exportaciones + validate-openapi.ps1 | 57/57 operaciones con muestras; 388 respuestas; cero errores/advertencias | openapi-report.json |
| Web | Auth/Catalog/Sales/Cash | Chrome, cuatro resoluciones | Ciclo completo usable | Automatización CDP + SQL | 76 capturas únicas, sin overflow/errores JS; observación de tamaños Login/Catalog | visual-report.json; sql-evidence.json |
| Rendimiento | Catalog/Sales/Cash | 26 ventas/mesas, 27 productos | Páginas acotadas y sin N+1 | FinancialQueryTest | Seis consultas con páginas 10 y 25 en los tres listados | query-measurements.json |

Los nombres de log abreviados corresponden a `.tools/acceptance-*`; las imágenes y JSON seguros se publican en [QA 1.5d.5](qa/1.5d.5/README.md). Los 33 reportes financieros publicados proceden de la ejecución final actual; conservan los nombres de archivo emitidos por sus clases originales.

## C. Apertura Cash

Se conserva `CashOperations`, su autorización y su idempotencia histórica de apertura. No se sustituye por un mecanismo nuevo. El recorrido HTTP comienza cerrando únicamente la apertura de fixture, consulta disponibilidad y abre por API; reproduce la misma clave y rechaza otra apertura. Las pruebas existentes cubren fondo inválido, caja inactiva, permiso, inactividad, concurrencia y pérdida de respuesta.

## D. Sales

Mostrador con cantidad, nota y pausa/recuperación; mesas con ocupación/liberación. El recorrido crea las cinco ventas antes de cambiar nombre, categoría, precio, estado y eliminación lógica del producto de fixture. Los cobros conservan precio 180 y nombre histórico. Las pruebas previas mantienen cantidad máxima 100, consolidación, notas, revisión, PIN y permisos. No se alteran productos operativos ni se inventan motivos de retirada/cancelación.

## E. Checkout

Efectivo 360, recibido 400 y cambio 40; tarjeta 180; transferencia 180; otro autorizado 180; mixto efectivo 80 recibido 100/cambio 20 y tarjeta 100. Total vendido **1080.0000**, pagos confirmados **1080.0000**, efectivo aplicado **440.0000**, recibido **500.0000**, cambio **60.0000**. SQL verifica importes persistidos y cada replay devuelve el resultado original sin duplicar pago o auditoría. No se simula una pasarela bancaria.

El descuento de este recorrido es cero. La suite conserva el caso previo de descuento total con aprobador exclusivo de fixture, y el rechazo productivo sin aprobación. No existe una autorización operativa nueva de descuentos.

## F. Folios

Contador SQL global del día comercial, protegido transaccionalmente; formato YYYYMMDD0001. Cinco cobros generan 202611170001–0005 y el nuevo turno continúa en 0006. Se mantienen pruebas de medianoche comercial/UTC, America/Mexico_City, rollback, límite 9999 y concurrencia real. Ningún folio operativo se modifica.

## G. Movimientos

Fondo 123.4567 + entrada 30.1234 − salida 10.0001 + efectivo neto 440 = **583.5800**. Existen solamente dos movimientos manuales en ese turno: los pagos no se duplican como entradas. Replays conservan sus IDs. La sonda SQL recalcula independientemente cada cierre a partir de movimientos/pagos persistidos.

## H. Arqueo

Antes de confirmar, resumen/reconciliación permanecen bloqueados y el formulario ciego oculta el panel financiero. Observación obligatoria independiente de la diferencia, sin tolerancia nueva. Se conservan casos cuadrado/faltante/sobrante/cero y rechazo de valores inválidos. La consulta operacional autorizada del saldo fuera del formulario sigue siendo el contrato existente; no se cambia la matriz de permisos.

## I. Cierre Cash

Se reutiliza la única coordinación Core: identidad → caja/sesión → ventas pendientes → saldo → arqueo/estado → Audit/recibo → commit. El recorrido rechaza cierre con cinco ventas pendientes y confirma cuando están resueltas. La unicidad, FK, CHECK y triggers existentes protegen el histórico. Las carreras verifican actividad simultánea; los fallos de auditoría revierten el conjunto. No se cierra ninguna sesión operativa.

## J. Nuevo turno

Apertura expresa con fondo cero, distinto ULID, movimientos vacíos y efectivo esperado cero. Una venta nueva pagada con tarjeta continúa el folio diario y no aporta efectivo. Nuevo arqueo distinto; consulta del cierre anterior idéntica. No hay traslado de fondo ni reapertura financiera.

## K. Idempotencia

Core conserva ámbito del actor, UUID4, hash canónico, appLock, transacción externa y hasta cinco intentos selectivos. Se mantienen pruebas de proceso interrumpido, pérdida de respuesta, error antes/después de commit, timeout, conexión terminada y deadlock SQL 1205 real. El recorrido reproduce aperturas, ventas, checkout, movimientos y cierre. Una intención distinta exige nueva clave; una clave histórica vencida no habilita reejecución.

La regresión final volvió a ejecutar **33 carreras financieras** documentadas: doce Checkout, nueve movimientos y doce reconciliación, además de las otras carreras previas de Core/apertura/Sales. Procesos PHP y sesiones SQL separados, espera LCK_M_* comprobada por los tests; los reportes de deadlock Checkout y Cash registran **1205** real. El caso de desconexión termina únicamente la sesión SQL de su propio worker de fresapos_test después del commit y recupera el recibo sin repetir movimiento. No se mata una conexión operativa ni se afirma una caída física de red SQL.

La prueba de compactación confirma que solo desaparecen cuerpos terminales vencidos; se conservan claves/referencias, pendientes y respuestas vigentes. El histórico financiero permanece consultable. `operations:compact` usa lotes de 50, máximo 500, READPAST/READCOMMITTEDLOCK. `schedule:list` confirma programación horaria con `withoutOverlapping`. No se ejecuta la compactación contra la base operativa ni se instalan tareas del sistema.

La salida de éxito informa cuántas respuestas se retiraron. Ante fallo, el comando registra `Operation compaction failed.` con diagnóstico saneado y devuelve FAILURE; no borra claves para ocultar el problema. En hosting se deben capturar tanto esa salida como los logs Laravel y alertar ante código distinto de cero. La tarea no se considera funcionando automáticamente por el solo hecho de aparecer en `schedule:list`.

## L. Seguridad

Se consulta la matriz SQL real, además de la configuración. Admin tiene el catálogo explícito; supervisor y cajero cuentan con las capacidades financieras existentes; mesero consulta/edita ventas y carece de cobro/cierre. `other` requiere `cash.administrative.execute` y solo se prueba con actor que ya lo tiene. No se otorgan permisos nuevos.

| Rol SQL existente | Crear/editar venta | Cobrar venta | Abrir/mover/cerrar caja | Consultar cierres | Pago other |
| --- | --- | --- | --- | --- | --- |
| admin | Sí | Sí | Sí | Sí | Sí |
| supervisor | Sí | Sí | Sí | Sí | No |
| cashier | Sí | Sí | Sí | Sí | No |
| waiter | Sí | No | No | No | No |

Los sí representan permisos existentes, no una concesión creada para la aceptación. Las credenciales y usuarios nuevos de los escenarios pertenecen exclusivamente a la base de pruebas.

Auth conserva Web/CSRF/PIN y API bearer Sanctum. Revocación individual, por usuario, cambio de contraseña y logout dejan el token anterior incapaz de operar. La suite mantiene inactividad, revocación Web, bloqueo PIN, cambio de empleado, permisos retirados, acceso horizontal y campos Locked. La caja compartida no se redefine como propiedad privada de un empleado.

## M. Auditoría

Un evento financiero por efecto confirmado; replay no duplica. Actor real, referencias y UTC. Fallo del listener obligatorio revierte transacción/recibo. Se conservan DTOs cerrados y saneamiento; no se publican tokens, claves, hashes, PIN, SQL, observaciones libres o datos de tarjeta en evidencia.

## N. SQL Server

Se incorpora una sonda de aceptación exclusivamente SELECT: `FinancialIntegrity`, compartida por la prueba y `scripts/inspect-financial-integrity.php`. Comprueba ULID en 55 columnas operativas, FK/CHECK confiables, nueve triggers obligatorios, cinco índices únicos obligatorios, trece columnas monetarias decimal(19,4), duplicados, huérfanos, pagos/sesiones originales, agregados cerrados y conciliaciones. Aborta ante una base distinta de fresapos/fresapos_test; no contiene reparaciones. Su ejecución operativa inicial pasó con cero violaciones.

La sonda requiere dependencias de desarrollo porque reside en Tests; es una herramienta local de aceptación, no un endpoint ni una tarea de hosting. No se añade migración. Los valores monetarios mantienen aritmética decimal; las mediciones de tiempo no se usan para calcular dinero.

## O. API

Sin rutas nuevas ni contratos públicos modificados. Se contrasta la especificación existente de 57 operaciones contra rutas exportadas. El recorrido exporta respuestas financieras y Auth/Core para ampliar la cobertura previa de 47 operaciones. Solo se publica el informe agregado; las muestras de login con credenciales efímeras permanecen privadas en `.tools`.

## P. Web

Ejecutado con Chrome **157.0.8103.79** real, perfil privado y servidor QA 8084 conectado exclusivamente a fresapos_test. **76 capturas únicas** en 1440×900, 1024×768, 768×1024, 390×844. Se consultó la skill computer-use; no hay herramienta node_repl/Computer Use compatible en esta sesión. Se utilizó Chrome instalado mediante CDP, sin instalar dependencias ni actuar sobre datos operativos.

Recorrido real desde login y apertura sin sesión previa hasta dos cierres: productos/notas, pausa/recuperación, efectivo insuficiente, cambio, tarjeta, transferencia, otro, mesa/mixto, pérdida deliberada de una respuesta HTTP 200 después de commit y recuperación por recarga, movimientos y arqueo ciego. Doble clic en apertura/cobros/movimientos, Tab y latencia 1500 ms con botones deshabilitados en cierre. SQL: cinco ventas cerradas, seis pagos, dos movimientos, dos sesiones/arqueos/cierres, cero pending y eventos únicos. Primer cierre: fondo 100 + 30 − 10 + efectivo 260 = **380**, vendido **900**; segundo turno vacío: cero. Los 21 recibos completed y uno failed por insuficiencia son coherentes con las acciones; failed no crea pago.

Sin desbordamiento horizontal o excepciones JavaScript en las capturas. Controles financieros de al menos 44 px. Se conserva una observación en ocho capturas Login/Catalog: controles no financieros menores de 44 px, incluido Entrar de 40 px. El primer intento había reutilizado indiscriminadamente el umbral del sondeo Cash; se delimitó la comprobación financiera y se mantuvo el hallazgo visible en el informe. No se presenta como certificación completa de accesibilidad ni se modifica una pantalla estable por preferencia de estilo. [Capturas y límites](qa/1.5d.5/README.md).

Se inspeccionaron visualmente capturas representativas de cada resolución. Chrome y el servidor QA se detuvieron, y la limpieza guardada confirmó **cero tablas** de fixtures antes de la regresión final.

CDP registró cero excepciones de ejecución y un error de recurso de red esperado `ERR_CONNECTION_CLOSED` al perder deliberadamente el HTTP 200 de checkout. Cero errores de consola inesperados. Se conserva el evento en [browser-errors.json](qa/1.5d.5/browser-errors.json).

## Q. Rendimiento

FinancialQueryTest mide consultas y tiempo SQL/pared de catálogo, mesas, ventas, agregado, checkout/folio, movimiento, saldo, cierre, resumen e historial. Compara páginas 10/25 sobre más de 25 fixtures, tras calentar permisos. Los tiempos locales son diagnóstico, no SLA o prueba de carga. No se introduce caché financiera ni se cambia arquitectura por microoptimización. La coordinación global de identidad sigue siendo un límite conocido a evaluar con carga representativa antes de producción.

Medición producida por la ejecución final, con 26 ventas/mesas y 27 productos:

| Operación | Consultas | Tiempo de pared, ms |
| --- | --- | --- |
| Catálogo, páginas 10 / 25 | 6 / 6 | 45.329 / 18.792 |
| Mesas, páginas 10 / 25 | 6 / 6 | 24.998 / 23.495 |
| Ventas, páginas 10 / 25 | 6 / 6 | 13.932 / 32.309 |
| Agregar producto | 28 | 135.828 |
| Checkout y folio | 57 | 209.228 |
| Movimiento | 24 | 81.873 |
| Saldo Cash | 10 | 16.326 |
| Cierre Cash | 31 | 151.706 |
| Resumen histórico | 9 | 33.965 |
| Historial paginado | 7 | 23.010 |

Sin crecimiento N+1 detectado al pasar de 10 a 25 filas en esos listados. Los repositorios inspeccionados usan paginación SQL e índices existentes; no se demuestra selección de planes óptimos con este volumen ni se establece un umbral de latencia productiva. Las carreras financieras comprueban esperas SQL reales controladas, no ausencia absoluta de bloqueo. [Medidas completas, incluido tiempo SQL](qa/1.5d.5/query-measurements.json).

## R. Regresiones

Se agregan FinancialCycleTest (tres casos), FinancialQueryTest (un caso), FinancialIntegrity y CLI de inspección. No se reconstruyen módulos ni se modifican componentes estables por estilo. No se ha encontrado un defecto financiero productivo que justifique cambiar Core/Sales/Cash.

Primer filtro `--filter Financial`: **49 pruebas / 608 aserciones, 1:22.558, 84 MB; un fallo**. El filtro también selecciona 45 casos existentes cuyos nombres contienen Financial. La expectativa nueva usaba `is_occupied`, pero el contrato estable expone `availability=free` y `active_sale_id`; se corrigió únicamente la prueba. Repetición del archivo FinancialCycleTest: **3 pruebas / 383 aserciones, 18.666 s, 64 MB, exit 0**. Después se añadieron dos aserciones explícitas de sufijo UTC que pasaron dentro de la suite final. FinancialQueryTest completó sus aserciones tanto en el primer filtro como en la regresión final y generó las mediciones. El primer fallo del sondeo visual por el umbral heredado se documenta en P; no se oculta como éxito.

## S. Calidad

Baseline aprobada: **589/6426, 13:32.342, 164 MB**. Regresión final aprobada: **593 pruebas / 6832 aserciones, 14:08.380, 166 MB, exit 0**, sin fallos, errores u omisiones. Diferencia observada: **+4 pruebas / +406 aserciones, +36.038 s y +2 MB**, conservando los 589 casos anteriores. Pint y Composer correctos; Larastan mantiene nivel 5, **349 archivos**, cero errores, frente a 348 iniciales por la nueva CLI de inspección. Sin dependencias nuevas.

OpenAPI final se reconstruye desde las ocho exportaciones HTTP de la suite recién ejecutada: **57 operaciones / 388 respuestas reales / 57 operaciones con muestras**, cero errores o advertencias. La verificación de cierre confirmó 26 huellas de tablas idénticas, diez hashes protegidos intactos, cero tablas en fresapos_test y nueve comprobaciones HTTP correctas. [Resultados seguros](qa/1.5d.5/test-results.json). Logs privados `.tools/acceptance-*`. El timeout de Composer se amplía solo en el proceso y se restaura en finally; no se edita Composer o el entorno.

| Comando PowerShell desde la raíz | Resultado registrado |
| --- | --- |
| `.\scripts\local.cmd composer test` inicial | 589/6426, 13:32.342, 164 MB, exit 0 |
| `.\scripts\local.cmd php vendor/phpunit/phpunit/phpunit --filter Financial` | 49/608, un fallo de expectativa nueva, exit 1; véase R |
| `.\scripts\local.cmd php vendor/phpunit/phpunit/phpunit tests/Integration/FinancialCycleTest.php` | 3/383, 18.666 s, 64 MB, exit 0; anterior a dos aserciones UTC adicionales |
| `.\scripts\local.cmd composer test` final | 593/6832, 14:08.380, 166 MB, exit 0 |
| `.\scripts\local.cmd composer lint` | Passed, exit 0 |
| `.\scripts\local.cmd composer analyse` | Nivel 5, 349 archivos, cero errores, exit 0 |
| `.\scripts\local.cmd composer validate --strict` | Válido, exit 0 |
| `.\scripts\local.cmd composer check-platform-reqs` | Requisitos satisfechos, exit 0 |
| `powershell.exe -NoProfile -ExecutionPolicy Bypass -File scripts/validate-openapi.ps1 -Responses .tools/acceptance-all-responses.json -Report .tools/acceptance-openapi-report.json` | 57 operaciones, 388 respuestas, 57 operaciones con muestras, cero errores/advertencias |
| `.\scripts\local.cmd php scripts/inspect-financial-integrity.php` | SELECT sobre fresapos; 21 comprobaciones sin violaciones, exit 0 |
| `.\scripts\local.cmd php artisan migrate:status` | Las 14 migraciones existentes siguen Ran; no se aplica ninguna |
| `.\scripts\local.cmd php artisan schedule:list` | operations:compact horario; sin cambios en tareas Windows |
| `.\scripts\local.cmd php .tools/acceptance-health.php` | Nueve comprobaciones de 8080 correctas |
| `.\scripts\local.cmd php .tools/acceptance-visual/empty-state.php` | Cero tablas después de QA y de nuevo al terminar la regresión |

Para la suite completa se usa COMPOSER_PROCESS_TIMEOUT=1800 únicamente en el proceso de PowerShell, con restauración en finally. Los logs con fallo se conservan junto a los satisfactorios. No se eliminan casos previos, se silencian excepciones o se reduce Larastan.

## T. Integridad

Comparación final ejecutada: **26/26 tablas idénticas**, incluidos migrations, usuarios, roles/permisos, catálogo, Sales, Cash, recibos y Audit; **10/10 archivos protegidos intactos**. Se conserva un administrador activo, tres sesiones Cash y sus dos arqueos previos; la sesión operativa abierta no se cerró. `.env`, `.env.testing`, conexión/autenticación Windows, credenciales y Composer permanecen intactos. [Informe sin hashes ni datos de filas](qa/1.5d.5/integrity-report.json).

Las 21 comprobaciones de invariantes volvieron a pasar sobre fresapos al finalizar, sin reparaciones. Todas las escrituras de escenarios se dirigieron a fresapos_test con TestDatabaseGuard y sin conexión operativa registrada. La comprobación final de esa base informó **cero tablas**. Chrome/servidor QA detenidos; app local 8080 sigue disponible. No hubo migración operativa ni una necesidad que justificara aplicar cambios de esquema o generar otro respaldo en esta entrega. Los respaldos previos no se sobrescribieron.

## U. Pendientes

- Azure SQL: prueba real con autorización/credenciales, TLS/Encrypt, TrustServerCertificate, firewall, permisos, compatibilidad de locks/DMV/transitorios y entorno del proveedor.
- cPanel o similar: disponibilidad real de PHP 8.3, pdo_sqlsrv/sqlsrv, ODBC, ionCube, conectividad SQL, TLS y dependencias de recursos visuales; no validado ni desplegado.
- Scheduler: la consulta real de Windows no encontró tareas del proyecto. Configurar explícitamente Windows Task Scheduler o cron del hosting para ejecutar `php artisan schedule:run` cada minuto, desde la raíz, cuenta autorizada, salida/logs y monitorización. No se creó ni modificó ninguna tarea del sistema. El comando y su lógica sí se prueban aisladamente.
- Restauración de ensayo y prueba de carga representativa no forman parte de las comprobaciones locales actuales; los respaldos anteriores con VERIFYONLY no prueban restauración completa.
- Tickets, inventario, descuentos operativos, motivos autorizados de retirada/cancelación, reembolsos, reaperturas, división de cuentas, reportes PDF/Excel y Android permanecen fuera de alcance.

## V. Conclusión

**ETAPA 1.5d.5: ACEPTADA LOCALMENTE**. Ciclo completo Cash–Sales y nuevo turno verificados por API y navegador, dinero conciliado desde SQL, las 589 pruebas anteriores conservadas y cuatro nuevas aprobadas, concurrencia/idempotencia reales, OpenAPI completo y controles de calidad correctos. Sin defectos financieros críticos sin resolver dentro del alcance; se conserva la observación visual no bloqueante de Login/Catalog y los límites de carga/accesibilidad indicados.

No quedan decisiones que requieran confirmación para cerrar esta aceptación local. Activar Scheduler en Windows/hosting, conectar Azure SQL o desplegar requiere una entrega/autorización posterior; no se ejecuta aquí. Sin commits, pushes, despliegues ni avance a otra etapa.
