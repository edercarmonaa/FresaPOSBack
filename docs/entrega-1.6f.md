# Entrega 1.6f — Aceptación integral del Bloque 05

Validación iniciada el 23 de noviembre y retomada el 25 de noviembre de 2026. **ETAPA 1.6f: ACEPTADA FUNCIONALMENTE CON OBSERVACIONES**. Datos de escritura exclusivamente en `fresapos_test`; diagnóstico operativo exclusivamente de lectura. La aceptación funcional y la investigación histórica se evalúan por separado.

## A. Resumen ejecutivo

Se revisaron las integraciones de motivos, descuentos, correcciones, Tickets y Reports con el núcleo financiero. No se implementaron funcionalidades de negocio, migraciones, permisos ni dependencias nuevos. Se conservó el trabajo sin commit de 1.6e.

Aceptación funcional local satisfecha, con 900 pruebas y 11625 aserciones en la regresión definitiva, contratos y documentos reales, concurrencia SQL y navegador. Integridad histórica explicada por reconstrucción exacta de las cinco diferencias; no se atribuye identidad física a partir de timestamps. Las 28 tablas operativas permanecen iguales durante 1.6f. Dos defectos confirmados corregidos: diagnóstico de excepciones y solapamiento de nombres largos en POS. Se conserva una observación local: fallos intermitentes de sincronización en intentos anteriores, con causa aún no demostrada y diagnóstico reforzado. Los pendientes de infraestructura externa se mantienen explícitos.

## B. Línea base

Rama `main`, HEAD `a0fc5a5e8aef885479d9774b4c6cdef7b116781c`. Se registraron Git, archivos modificados/no confirmados, Composer JSON/lock, 17 migraciones y esquema de 28 tablas, contratos, rutas, pruebas, arquitectura y procedimientos locales. Se leyeron los ocho informes solicitados de 1.5d.3 a 1.6e. Ninguno faltó. PHP 8.3.36, Laravel 13.42.0, SQL Server Express `AUDITORIA`, autenticación Windows y zona comercial conservados.

Las cifras históricas de 1.6e (891/10615, 609 respuestas, 102 capturas, diez PDF/diez XLSX) no se reutilizan como resultados actuales. Primer intento 1.6f: Composer interrumpió a los 300 segundos; no aprobado. Segundo intento del 23/11: alcanzó 756/891 antes de la interrupción de herramientas y procesos; no dejó resultado final, tampoco aprobado. Se identificaron y retiraron solo sus fixtures en `fresapos_test`, mediante salvaguarda exacta y `down()` existentes. No se modificó `fresapos`.

Línea base completa del 24/11: `composer test -- --log-junit .tools/block05-baseline-junit.xml`, **891 pruebas / 10617 aserciones**, 26:01.954, 274 MB, salida 0. `composer lint`, `composer analyse` (nivel 5, 425 archivos), `composer validate --strict` y `composer check-platform-reqs`: salida 0. Se conserva el detalle privado, sin sustituirlo por resultados históricos.

## C. Arquitectura

Core conserva transacciones, idempotencia y eventos; Sales mantiene ventas, motivos, descuentos, correcciones, pagos y folios; Cash conserva sesiones/movimientos/conciliaciones; Tickets y Reports consumen contratos públicos. Auth/Users validan acceso y permisos; Audit participa en las operaciones que requieren evidencia. Sin HTTP interno, ciclos, repositorios transaccionales paralelos o cálculos distintos por formato.

Dos correcciones productivas: `ExceptionDiagnostic` publica metadatos técnicos cerrados, sin serializar objetos Throwable, sus mensajes ni causas; el CSS de tarjetas POS contiene el texto de la imagen alternativa y lo limita a tres líneas, conservando el nombre completo en el título y etiqueta accesible. Se preservan clasificación y reintentos SQL existentes. Nuevas pruebas integrales, calendario, contratos, concurrencia y logs; el benchmark existente se amplía con subconjuntos reales para límites de exportación. No se reconstruyen módulos.

## D. Matriz de aceptación

Cada escenario utiliza fixtures reconocibles, usuarios y caja de prueba; ninguna venta operativa. Evidencia de ejecución actual en [QA](qa/1.6f/README.md) y JUnit privado. El resultado de cada fila está vinculado a casos concretos y a la conciliación compuesta, no a una lista genérica de módulos.

| Requisito | Origen | Módulos | Servicio/endpoint | Escenario | Datos `_test` | Esperado | Real | Evidencia actual | Estado |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| Motivos | 1.6a | Sales/Users/Audit | `/admin/sales/reasons` | Normalizar, desactivar, conservar código e impedir motivo incompatible | Códigos QA, tipos retiro/cancelación | Solo activos compatibles; identidad estable | Conforme en regresión final | SaleReasonsTest::test_catalog_normalization_uniqueness_immutable_identity_and_durable_administration; test_inactive_wrong_type_or_missing_reason_cannot_mutate_a_sale | Aprobado |
| Retiros | 1.6a | Sales/Catalog/Audit | `SalesOperations::removeItem` | Retirar última línea y conservar instantánea | Una línea de 180 | Línea histórica, sin pago/folio | Conforme en regresión final; compuesto conserva removed_at | SaleReasonsTest::test_removal_preserves_original_line_reason_snapshot_and_closed_audit_without_cash_effects; Block05AcceptanceTest | Aprobado |
| Cancelaciones | 1.6a | Sales/Core/Audit | `SalesOperations::cancel` | Abierta/pausada/mesa, replay; rechazar pagada | Motivo QA_CANCEL | Libera mesa, no ingreso ni devolución | Conforme en regresión final | SaleReasonsTest::test_unpaid_cancellation_is_atomic_historical_idempotent_and_releases_table; test_paid_sales_and_confirmed_payments_never_become_cancellation_or_refund | Aprobado |
| Descuentos | 1.6b | Sales/Core/Users | `SalesOperations::setDiscount` | Un descuento vigente, recalcular porcentaje, rechazar fijo excesivo | 180; 25 %=45; total135 | Exactitud decimal y autorización | Conforme en regresión final; 225 total confirmado compuesto | SaleDiscountsTest::test_apply_replace_remove_preserves_one_discount_author_and_history_with_exact_replay; Block05AcceptanceTest | Aprobado |
| Checkout | 1.5d.3/1.6b | Sales/Cash/Core | `SaleCheckout::confirm` | Efectivo y mixto con cambio | 135 recibido200, cambio65 | Aplicado135; cambio no ingreso | Conforme en regresión final | SaleDiscountsTest::test_checkout_collects_only_discounted_total_with_mixed_payments_and_real_change; Block05AcceptanceTest | Aprobado |
| Total cero | 1.6b | Sales/Cash/Tickets/Reports | `SaleCheckout::confirm` | Porcentaje100 y fijo180 | Venta180 total0 | Cerrada y folio, cero pagos ficticios | Conforme en regresión final; una venta cero en compuesto | SaleDiscountsTest::test_zero_total_checkout_issues_folio_without_payments_income_or_cancel_reason; SaleReceiptsTest | Aprobado |
| Correcciones | 1.6c | Sales/Core/Audit | `SaleCorrections::write` | Cantidad de1 a2, notas, devolución bloqueada | Venta180→360 | Mismo ULID/folio/primer cierre, historia intacta | Conforme en regresión final | SaleCorrectionsTest::test_reopening_preserves_complete_financial_history_and_blocks_generic_writes_and_cash_closing; Block05AcceptanceTest | Aprobado |
| Cobro adicional | 1.6c | Sales/Cash/Core | `SaleCorrections::write(confirm)` | Original efectivo80/tarjeta100; adicional tarjeta180 | Total360 | Solo180 adicional; pagos originales intactos | Conforme en regresión final | SaleCorrectionsTest::test_increment_uses_existing_allocation_with_mixed_additional_payments_and_preserves_original_rows; Block05AcceptanceTest | Aprobado |
| Folios | 1.5d.3/1.6c | Sales/Core/Tickets | `SaleCheckout::confirm` | Replay, corrección, mes/año | Tres ventas pagadas, límites05:59:59/06:00UTC | Tres folios; uno por venta; fecha local | Conforme en regresión final | Block05AcceptanceTest::test_month_and_year_changes_keep_sql_report_bounds_and_persisted_folios_aligned; CheckoutConcurrencyTest | Aprobado |
| Sesiones Cash | 1.5d.1–5 | Cash/Sales/Core | `CashOperations::open`, `CashClosings::close` | Turnos separados y exclusión mutua | Fondos QA | Una abierta; no traslado implícito de diferencia | Conforme en regresión final | CashConcurrencyTest; CashReconciliationTest::test_replay_lost_response_conflict_and_new_opening_keep_original_history | Aprobado |
| Arqueos | 1.5d.4/1.6c | Cash/Users/Audit | `CashClosings::close` | Pendientes, permisos, conteo exacto y diferencia± | Compuesto contado355 | Esperado355/diferencia0 solo tras cierre | Conforme en regresión final | CashReconciliationTest::test_blind_queries_and_observation_validation_do_not_offer_a_balance_oracle; Block05AcceptanceTest | Aprobado |
| Tickets | 1.6d | Tickets/Sales/Cash | `SaleReceipts::view` | Historia, métodos, cambio, cero, corrección | Catalog renombrado a precio999 después de cobro | Precios/nombres originales, no secretos | Conforme en regresión final | SaleReceiptsTest::test_closed_sale_uses_historical_products_prices_notes_folio_first_close_and_employee; Block05AcceptanceTest | Aprobado |
| Reimpresiones | 1.6d | Tickets/Core/Audit | `SaleReceipts::reprint` | Misma intención/replay y versión obsoleta | Comprobante ya pagado | Una auditoría, mismo folio, no otro cobro | Conforme en regresión final | SaleReceiptsTest::test_reprint_request_and_replay_audit_once_without_financial_mutations; Block05ConcurrencyTest(reprint_confirmation) | Aprobado |
| Reportes | 1.6e | Reports/Sales/Cash | OperationalReports; `/reports/*` | Nueve reportes, mismo ámbito Web/API | Ingreso495, descuentos225, cuatro unidades | Mismos totales/filtros/historia, abierto sin saldo ciego | Conforme en regresión final | Block05AcceptanceTest::test_composite_financial_journey_agrees_across_sales_cash_receipts_web_api_pdf_and_xlsx; ReportsTest/HttpTest | Aprobado |
| PDF | 1.6e/f | Reports/Users/Audit | `POST /reports/{report}/exports/pdf` | Nueve documentos y fronteras0/1/300/>300 | Conjuntos completos de ventas | Archivo real, sin truncar; rechazar exceso | Archivos reales verificados, sin truncado | Block05AcceptanceTest; ReportsPerformanceTest; files-check.json | Aprobado |
| XLSX | 1.6e/f | Reports/Users/Audit | `POST /reports/{report}/exports/xlsx` | Nueve libros y fronteras0/1/300/1000/>1000 | Filas completas, folios texto y fechas | Tipos correctos, sin fórmulas/macros ni truncado | Archivos reales verificados, sin truncado | Block05AcceptanceTest; ReportsPerformanceTest; files-check.json; xlsx-text-types.json | Aprobado |
| Seguridad | 1.3/1.6a–f | Auth/Users/Core | Web/API/PIN/ExceptionDiagnostic | Identidad/permisos revocados, CSRF/XSS, logs cerrados | Usuarios y marcadores sintéticos QA | Rechazo servidor; ninguna información sensible serializada | Conforme en regresión final | AuthenticationTest; ApiConventionsTest; Block05DiagnosticTest; SaleDiscountsPinConcurrencyTest; pruebas HTTP/Livewire | Aprobado |
| Auditoría | 1.5d/1.6a–f | Audit/Sales/Cash/Tickets/Reports | Eventos de operación | Fallo obligatorio→rollback; replay→sin duplicado | Una corrección/reimpresión y18 exportaciones | Actor UTC y evidencia; sin eventos de transacción revertida | Conforme en regresión final | SaleReasonsTest/SaleDiscountsTest/SaleCorrectionsTest::test_audit_failure*; Block05AcceptanceTest | Aprobado |
| Idempotencia | 1.5d.2a/1.6a–d | Core/Sales/Cash/Tickets | Recibos durables | UUID inválido, payload distinto, pendiente, replay, respuesta perdida | Claves exclusivas de fixtures | No duplica pagos/folios/movimientos/eventos | Conforme en regresión final | IdempotencyTest/ConcurrencyTest; SaleCorrectionsConcurrencyTest; CashReconciliationTest | Aprobado |
| Concurrencia | 1.5d/1.6a–f | Core/Sales/Cash/Tickets/Reports | Procesos PHP + SPID distintos | 18 criterios / 16 carreras y deadlock1205 real | Bloqueos `_test` observados DMV | Atomicidad y documentos coherentes o rechazo saneado | 18 criterios / 16 intercalaciones, sin duplicados | Matriz de secciónP; concurrency.json | Aprobado |
| Integridad SQL Server | 1.2b/1.5d/1.6f | Core/Sales/Cash/Audit | FK/índices/triggers/FinancialIntegrity | Guías SQL y diagnóstico operativo SELECT/DBCC |28tablas;64columnasULID | Sin huérfanos, importes/folios/arqueos inconsistentes | 28 controles / 64 columnas ULID; cero infracciones | inspect-block05-history.php; operative-integrity.json | Aprobado |


## E. Motivos

El catálogo operativo sigue vacío y no se siembra. Los servicios exigen motivo activo compatible y permiso vigente para retirar/cancelar. Administración, códigos estables, desactivación, instantáneas históricas, línea propia/ajena, venta abierta/pausada y bloqueos de pagadas se comprueban con SaleReasonsTest/HttpTest/LivewireTest/ConcurrencyTest. Los motivos `QA_REMOVE`/`QA_CANCEL` pertenecen exclusivamente a fixtures.

El escenario compuesto retira la última línea sin borrarla físicamente, conserva `removed_at` y cancela la venta. Esta operación no crea pagos/folio ni ingresos y no impide el cierre cuando se ha resuelto el pendiente.

## F. Descuentos

Porcentaje entero 1–100 y fijo exacto; una autorización vigente por venta. Aplicación, sustitución, eliminación, redondeo a cuatro posiciones, recalcular porcentajes y rechazar fijo superior al subtotal permanecen cubiertos. Reporte presenta solamente el descuento confirmado vigente. La conciliación compuesta usa 25 % de 180 = 45, total 135; descuento completo adicional 180, total de descuentos 225. No se acumulan sustituciones históricas como descuentos simultáneos.

## G. Correcciones

El escenario de mesa cobra 180 con efectivo 80/tarjeta 100; corrige cantidad de uno a dos y confirma total 360 con tarjeta adicional 180. Conserva ULID, folio, primer cierre y filas originales de pagos. Replay devuelve el mismo resultado; un evento funcional resumido. Los casos actuales incluyen sin diferencia, sin cambios, descartada, activa y propuesta que exige devolución. Devoluciones/reclasificación de pagos históricos siguen bloqueadas. Correcciones activas bloquean cierres incompatibles.

## H. Tickets

Comprobantes comparados con Sales: productos/precios/notas históricos, subtotal, descuento, total, pagos, recibido/cambio, total cero y corrección. Consulta y preparación son auxiliares, no otro cobro. Misma intención de reimpresión conserva una auditoría, folio y estado financiero. Revisión anterior durante confirmación concurrente se rechaza, evitando emitir una copia obsoleta.

## I. Reportes

Se contrastan resumen, ventas, productos, métodos, movimientos, sesiones, arqueos, descuentos y correcciones. Una venta corregida sigue siendo una venta; canceladas/retiradas no inflan ingresos/productos. Nombre/precio de Catalog se modifica únicamente en fixture después de cobrar: los documentos mantienen historia. Métodos usan importe aplicado; cambio, fondo y movimientos manuales se distinguen de ingresos.

Rango comercial por primer cierre presenta el estado confirmado vigente; no se inventa un libro contable por fecha del cobro adicional. Para conciliar se utiliza el mismo ámbito de ventas y pagos.

## J. PDF y XLSX

Ejecución actual: nueve PDF y nueve XLSX del escenario compuesto, más tres PDF (0/1/300 filas) y cuatro XLSX (0/1/300/1000). **12 PDF, 26 páginas y 13 XLSX**, todos reales y comprobados. El PDF de 300 filas ocupa quince páginas, con encabezados repetidos y las 300 ventas. Rechazo por encima de 300 PDF y 1000 XLSX. Se revisaron las nueve páginas compuestas, sus nueve vistas XLSX, los casos 0/1 y el inicio/final de los libros grandes; las quince páginas del PDF grande se renderizaron y revisaron en contacto, con primera/última ampliadas. La inspección de texto, celdas y márgenes abarca todos los datos.

Los **1307 folios** de los trece XLSX son strings con formato `@`, completos y exactos; no hay pérdida numérica en el XML. ArtifactTool los representa inicialmente en notación científica: el adaptador de revisión usa `0` únicamente en su copia de imagen, verifica el texto importado y comprueba SHA-256 inalterado del archivo original. No se guardó esa copia ni se cambió el exportador. Excel de escritorio sigue pendiente.

Todos los formatos comparten OperationalReports. Se comparan datos JSON, representación Web, celdas/totales/filtros/folios de XLSX y texto de PDF del mismo conjunto. Sin tolerancia monetaria añadida. Exportación completa independiente de página. Texto literal contra fórmulas, tipos numéricos/fechas, cuatro decimales, una hoja y ausencia de macros verificados. PDF real, filtros y encabezados, sin archivos públicos de producción.

## K. Cash

Conciliación compuesta: fondo 100 + efectivo aplicado 215 + entrada manual 50 − salida 10 = **355.0000**. Tarjeta 280 queda fuera de efectivo físico. Recibido 200 y cambio 65 del mostrador no se suman otra vez. Dos movimientos manuales y cuatro pagos confirmados, sin pago ficticio de la venta cero. Arqueo contado 355, esperado 355, diferencia cero, cierre confirmado.

Sesiones abiertas publican esperado/contado/diferencia null en Reports, también en exportaciones. Se repiten los casos de arqueo ciego, roles, bloqueo de pendientes, cierre simultáneo, fondos y turnos independientes. No se utiliza un ajuste manual para ocultar un error.

## L. Conciliación financiera cruzada

Tres ventas pagadas: mostrador descontado 135, mesa corregida 360 y total cero 0. Subtotal 720; descuentos 225; ingreso 495; cuatro unidades confirmadas. Efectivo 215 + tarjeta 280 = **495.0000**. Una cancelada adicional conserva evidencia y queda fuera del ingreso. Tres folios; corrección y reimpresión no consumen otro consecutivo. Cash esperado y contado 355, diferencia cero. Es el mismo escenario a través de servicios, Web, API, PDF y XLSX; no son cifras operativas.

## M. Seguridad

Se repiten Web/Sanctum, usuario activo/inactivo, revocación de token/sesión, PIN, permisos efectivos y reautorización bajo bloqueo. Tickets/Reports exigen capacidades propias y de los propietarios; no existe bypass por rol. Alcance compartido del establecimiento preservado, sin inventar propiedad exclusiva del creador. ULID/folio/campos desconocidos, CSRF, XSS, errores con debug activo/inactivo y protección del arqueo ciego incluidos.

Defecto comprobado y corregido: un RuntimeException, especialmente con QueryException previa, podía serializar mensaje/SQL/bindings al logger. La regresión inicial sin SQL ejecutó 3 casos/16 aserciones, con dos fallos reales. Se conserva diagnóstico por clase/archivo/línea y códigos SQL limitados; se eliminan objetos/mensajes/causas de su contexto. Las pruebas previas se refuerzan para comprobar la salida serializada, sin eliminar casos. Los marcadores sintéticos históricos de logs no se borran ni se presentan como filtración de credenciales operativas demostrada.

## N. Auditoría

Retiro, cancelación, descuento aplicado/reemplazado/quitado, corrección efectiva, reimpresión y exportación mantienen actor UTC, recurso y evidencia cerrada. Fallo del listener obligatorio revierte la operación; no queda evento funcional de la transacción revertida. Una corrección sin cambios no crea «Venta corregida». El compuesto verifica una corrección y una reimpresión pese al replay; las 18 exportaciones son 18 generaciones expresas, no cobros ni confirmaciones de entrega física.

## O. Idempotencia

Core existente, UUID v4, contexto/actor, hash canónico, exclusión SQL y recibo durable. Se repiten clave inválida, misma/distinta carga, pending/completed, rollback, respuesta perdida, desconexión, deadlock y reintento. Sin otro mecanismo ni tablas nuevas. Replay no duplica pagos, movimientos, folios, correcciones o auditoría. Exportaciones no se anuncian como una operación financiera idempotente: cada solicitud genera un documento y su evento auxiliar.

## P. Concurrencia

Procesos PHP y conexiones SQL independientes, barreras observables y DMV `LCK_M_*`. No se cuentan llamadas secuenciales como carreras. Código 1205 real requerido en casos de deadlock. Las esperas y límites originales se conservan.

Dieciocho criterios cubiertos por **dieciséis intercalaciones distintas** de la ejecución final. Dos criterios comparten evidencia: dos confirmaciones/cobro adicional y reapertura/cierre con corrección activa; no se cuentan como procesos nuevos. La respuesta perdida observa pending409 y replay200 sin atribuirle un bloqueo SQL inexistente.

| Criterio | Fuente actual | Resultado real |
| --- | --- | --- |
| Retiro contra Checkout | `reasons-race-remove_checkout.json` | first_status=200; second_status=409; transient_sql_codes=[] |
| Cancelación contra Checkout | `reasons-race-cancel_checkout.json` | first_status=200; second_status=409; transient_sql_codes=[] |
| Descuento contra Checkout | `discounts-race-checkout.json` | first_status=200; second_status=409; transient_sql_codes=[] |
| Sustitución simultánea | `discounts-race-replace.json` | first_status=200; second_status=409; transient_sql_codes=[] |
| Reapertura contra cierre Cash | `corrections-race-reopen_close.json` | first_status=200; second_status=409; additional_payments=0; correction_events=0; transient_sql_codes=[]; LCK_M_U |
| Corrección contra arqueo | `corrections-race-reopen_count.json` | first_status=200; second_status=409; additional_payments=0; correction_events=0; transient_sql_codes=[]; LCK_M_U |
| Dos confirmaciones de corrección | `corrections-race-two_confirms.json` | first_status=200; second_status=409; additional_payments=1; correction_events=1; transient_sql_codes=[]; LCK_M_U |
| Cobro adicional simultáneo | `corrections-race-two_confirms.json` | first_status=200; second_status=409; additional_payments=1; correction_events=1; transient_sql_codes=[]; LCK_M_U |
| Corrección contra modificación de productos | `corrections-race-products.json` | first_status=200; second_status=409; additional_payments=0; correction_events=0; transient_sql_codes=[]; LCK_M_U |
| Cierre Cash contra venta abierta | `reconciliation-race-open_after.json` | first_status=200; second_status=503; closures=1; transient_sql_codes=[] |
| Cierre Cash contra corrección activa | `corrections-race-reopen_close.json` | first_status=200; second_status=409; additional_payments=0; correction_events=0; transient_sql_codes=[]; LCK_M_U |
| Dos cierres Cash | `reconciliation-race-two_keys.json` | first_status=200; second_status=409; closures=1; transient_sql_codes=[] |
| Dos aperturas Cash | `cash-1.5d1-race-application.json` | sessions=1; primero=created; segundo=conflict; LCK_M_U |
| Reintento tras respuesta perdida | `corrections-race-lost.json` | first_status=200; second_status=200; pending_status=409; additional_payments=1; correction_events=1; transient_sql_codes=[] |
| Deadlock SQL real | `corrections-race-deadlock.json` | first_status=200; second_status=200; additional_payments=1; correction_events=1; transient_sql_codes=[1205]; LCK_M_RS_U |
| Lectura de reporte durante cambios | `reports-concurrency.json` | reader_status=503; after_commit_income="360.0000"; LCK_M_X |
| Exportación durante cobro | `block05-concurrency-export_checkout.json` | primero=503; segundo=200; LCK_M_X |
| Reimpresión durante confirmación corregida | `block05-concurrency-reprint_confirmation.json` | primero=200; segundo=409; LCK_M_U |

Lectura/exportación puede completar con una versión coherente o responder 503 saneado ante contención; nunca se entrega un archivo parcial. El render sucede fuera de la transacción de lectura. SERIALIZABLE local y restauración de opciones de conexión; no se habilita SNAPSHOT globalmente ni se usa NOLOCK.

## Q. Integridad de las cinco tablas

Referencia: `.tools/reports-projection-before.json`, capturada el 20/11/2026 alrededor de 17:22:44 UTC. Es un **manifiesto por tabla de columnas, conteo y SHA-256 de filas JSON ordenadas**, con IDs de migraciones. No es respaldo ni almacena imágenes anteriores por fila. No prueba por sí solo qué fila cambió ni la identidad física de quien operó.

La investigación selecciona las columnas originales y reconstruye únicamente candidatos **en memoria PHP**. Si el SHA-256 completo vuelve a coincidir con el manifiesto, se identifica exactamente la diferencia de valores respecto de esa referencia, sin escribir/revertir datos. [Verificador](../scripts/inspect-block05-history.php), dos archivos privados de entrada y salida agregada sin secretos. El historial criptográfico no sustituye la autenticidad/origen de la referencia.

| Tabla | Diferencia exacta comprobada | Evidencia y certeza | Riesgo y acción |
| --- | --- | --- | --- |
| audit_security_events | 68→71; tres filas añadidas: conciliación, cierre y apertura | Al excluir solo esas tres filas en memoria se obtiene el SHA histórico; eventos UTC 20/11 17:27:55 y 17:28:30. Alta para los valores | No se observa ingreso duplicado. Conservar eventos |
| cash_reconciliations | 2→3; un arqueo añadido, esperado/contado 350 y diferencia cero | SHA histórico exacto al excluir únicamente ese arqueo; referencias cruzadas con sesión/Audit. Alta | Sin discrepancia material nueva; conservar |
| cash_sessions | 3→4; una fila pasa open→closed y otra apertura añade fondo 500 | Retirar la nueva solo en memoria y restablecer status/closed_at/closed_by/updated_at del candidato reproduce el SHA histórico exacto. Alta | No se trasladó automáticamente el fondo 350 a 500; es una apertura expresa. Conservar ambas |
| idempotency_records | 17→18; recibo completed de cash.session.close, HTTP 200 | Recurso y UTC coinciden con cierre/arqueo. Restar solo esa fila reproduce el SHA histórico. Alta | No es un segundo cierre; conservar el recibo |
| auth_access_sessions | 15→15; una sesión Web cambia actividad/updated_at y expiración idle | Búsqueda acotada de candidatos en memoria reproduce SHA completo: actividad anterior 17:19:09.353, expiración 17:49:09.353; actuales 17:29:03.877/17:59:03.877. Alta | Coherente con actividad y extensión de 30 minutos; sin cambio de identidad/hash/token/revocación. Conservar |

Los hashes reconstruidos coinciden en **las cinco tablas**. Así se descartan otras diferencias de valores en sus columnas proyectadas respecto del manifiesto, bajo la confianza en esa referencia. Las otras 23 tablas, incluidas ventas/líneas/pagos/folios, siguen iguales frente a ella. No se identificaron filas de QA agregadas a la operación. Se vincula una cuenta registrada, no una persona física ni una autorización externa independiente. No se deduce legitimidad solo de timestamps.

Esquema actual: restricciones, claves, unicidad, huérfanos, importes, pagos, folios y arqueos se inspeccionan mediante DBCC y FinancialIntegrity; migraciones sin cambios. No había un manifiesto histórico completo de todas las definiciones de índices/restricciones: no se afirma comparación histórica absoluta de cada objeto. Se comparan las definiciones/índices que sí conserva la evidencia y el inicio/final de 1.6f.

Comparación inicio/final: **28/28 tablas** con conteos, columnas proyectadas y SHA idénticos; los metadatos capturados de columnas/índices/restricciones y las 17 filas de migraciones coinciden. El JSON de integridad enumera el alcance; no se compararon íntegramente los cuerpos de todos los objetos DDL. DBCC sin infracciones; FinancialIntegrity aprobado en sus 28 controles y 64 columnas ULID. Se conservaron las 59 entradas históricas de columnas de índices disponibles, sin extrapolar a definiciones históricas ausentes. Catálogo operativo de motivos: cero. Todos los fixtures retirados de `_test`, con comprobación de cero tablas. Salud de 8080: veinte comprobaciones aprobadas, sin iniciar sesión ni escribir datos operativos.

Contraste adicional de solo lectura entre `fresapos` y el esquema creado por las 17 migraciones en `fresapos_test`: **todos los grupos coinciden**. Incluye atributos de columnas, identidad, defaults/computadas, PK/índices únicos y demás índices, columnas de FK con acciones/estado/confianza, CHECK y triggers con sus cuerpos/opciones; se consulta también la presencia de rutinas SQL adicionales. Se excluye únicamente la tabla del historial de migraciones, que se verifica por separado. Se normalizan nombres automáticos de restricciones, saltos de línea y el verbo CREATE/ALTER del encabezado; no se reemplaza el cuerpo de las reglas. Conteos y SHA de cada grupo en [esquema actual](qa/1.6f/current-schema.json). Este resultado actual no demuestra cómo eran todos los cuerpos DDL el 20/11.

Alcance temporal: la comparación inicio/final conserva los atributos capturados de columnas/índices y el nombre/tipo/tabla de las restricciones. La captura complementaria contrasta las definiciones actuales contra las migraciones, incluidos CHECK y cuerpos de triggers. No existe una referencia histórica completa de esos cuerpos para probar su evolución anterior. DBCC y los controles financieros no sustituyen un historial completo de DDL ni prueban por sí solos la autoría de cambios externos.

## R. Archivos protegidos

| Archivo | Frente al inicio de 1.6e | En 1.6f |
| --- | --- | --- |
| .env | Intacto | Intacto frente al inicio de 1.6f |
| .env.testing | Intacto | Intacto frente al inicio de 1.6f |
| config/database.php | Intacto | Intacto frente al inicio de 1.6f |
| config/session.php | Intacto | Intacto frente al inicio de 1.6f |
| config/filesystems.php | Intacto | Intacto frente al inicio de 1.6f |
| phpunit.xml | Intacto | Intacto frente al inicio de 1.6f |
| phpstan.neon | Intacto | Intacto frente al inicio de 1.6f |
| Modules/Users/config/security.php | Intacto | Intacto frente al inicio de 1.6f |
| composer.json | Modificado con autorización expresa para PDF/XLSX | Intacto frente al inicio de 1.6f |
| composer.lock | Modificado con la misma autorización | Intacto frente al inicio de 1.6f |

No se restauran archivos ni se ocultan los cambios autorizados. Inventarios inicial/final conservan cambios previos sin commit.

## S. Dependencias

Dompdf 3.1.6 y PhpSpreadsheet 5.11.0, incorporados con aprobación en 1.6e. Comparación contra HEAD: once paquetes añadidos necesarios para PDF/XLSX y sus transitivos, cero actualizaciones/eliminaciones de paquetes existentes. No se actualizan o instalan paquetes en 1.6f. Compatibilidad local PHP 8.3/plataforma/lock comprobada por Composer; no certifica el SAPI de hosting.

Transitivas añadidas en esa autorización: `composer/pcre` 3.4.0, `dompdf/php-font-lib` 1.0.2, `dompdf/php-svg-lib` 1.0.2, `maennchen/zipstream-php` 3.2.2, `markbaker/complex` 3.0.2, `markbaker/matrix` 3.0.1, `masterminds/html5` 2.12.0, `phpoffice/wmf` 0.2.1 y `sabberworm/php-css-parser` 9.5.0. El [delta del lock](qa/1.6f/dependency-delta.json) enumera los dos paquetes directos y estas nueve transitivas, sin actualizaciones ni eliminaciones.

## T. API y OpenAPI


OpenAPI 3.1 validado: **74 operaciones / 683 respuestas actuales**, cero errores y cero advertencias. Catorce fuentes directas de la regresión final, todas con fecha posterior a su inicio; no se reutilizaron muestras históricas. Cobertura de las 74 rutas/métodos efectivos. Se excluye una sonda negativa DELETE no registrado (405), documentada en procedencia; no se elimina una operación real. `validate-openapi.ps1` utiliza openapi-spec-validator y openapi-schema-validator 0.9.0. Resultado y procedencia en QA.

Rutas/métodos reales, bearer/acceso vigente, entradas, recursos, códigos JSON, paginación y errores comparados. Se conserva la cobertura previa y se capturan respuestas nuevas para las 74 operaciones; los rechazos de frontera se distinguen de escenarios autenticados exitosos. Los archivos binarios reales se inspeccionan como PDF/XLSX, no se simulan como JSON. No hay endpoints nuevos de negocio.

## U. Web

Chrome real: **341 capturas únicas**, cuatro resoluciones y contactos de revisión, más documentos térmicos y descargas. Validación de errores, ancho global, controles financieros, teclado, modales/confirmaciones, permisos, filtros y estados. Dos recorridos de descuento: validación0, 10%, doble clic, sustitución fija15 con respuesta200 deliberadamente perdida, recuperación y eliminación; SQL confirma dos eventos de cada tipo por dos intenciones, sin duplicados. Creación de dos motivos de fixture, retiro de última línea y cancelación; corrección de nota confirmada con mismo folio/total360; mesero consulta sin imprimir, cambio por PIN y CSRF nativo desde8086 hacia8085 rechazado419. Los hashes de ocho tablas financieras no cambian durante Tickets. Nueve parejas PDF/XLSX nativas: 409 valores de detalle coincidentes. Repetición final con fixtures nuevos: POS sin rechazos HTTP ni banner de error, colector de cierre normalizado y cuatro archivos adicionales de arqueo/sesiones cerradas retenidos y validados. Los conjuntos compuesto y nativo son fixtures distintos, no importes operativos.

Navegador integrado/CUA falló al iniciar; también falló el kernel de node_repl con `windows sandbox failed` al retomar. Se utiliza Chrome real instalado, perfil y servidor QA independientes, base `_test`, sesiones/cache/archivos separados. El servidor operativo 8080 no se usa para escribir fixtures. Login/Catalog conservan tamaños de controles históricos, registrados separadamente de controles financieros de 44 px. No se atribuyen a esta etapa capturas anteriores.

## V. Impresión

Inventario Windows consultado sin enviar trabajos: Brother DCP-L5660DN series y dos salidas virtuales. Esa configuración no acredita disponibilidad física ni compatibilidad con papel de 80 mm. El botón invocó `window.print` una vez y se observó `beforeprint`; el navegador sin interfaz no acredita entrega física.

HTML y vista previa de Tickets; PDF del motor de impresión del navegador de ancho nominal 80 mm, área útil 74 mm y altura dinámica. Se revisan producto/nota largos, descuento, pago mixto, cambio, cero, corregida y reimpresión. Controles Web ausentes en papel, sin recorte horizontal. PDF de impresión no equivale a salida en impresora. **Impresión física pendiente**; no se instaló controlador ni se confirmó entrega de papel.

## W. Rendimiento

1001 ventas reales de fixture. Página de 10 filas: **26 consultas / 225.082 ms**; página de 100 filas: **26 consultas / 172.106 ms**. Pico registrado por el benchmark: **220200960 bytes**; plan capturado=True. Línea base del 24/11: 26 consultas, 61.284/56.158 ms y 218103808 bytes. Son mediciones individuales locales con caché; no se presenta un SLA ni se ocultan diferencias de tiempo. Los subconjuntos de 300 y 1000 se exportaron completos; los excesos se rechazaron.

Se reutiliza el benchmark de 1001 ventas creadas por servicios reales, con páginas 10/100 y totales completos. Se añade distribución controlada de 300/700/1 entre días para validar subconjuntos de exportación de 300/1000 y casos vacío/una fila. Se conserva la prueba de plan estimado equivalente, no un supuesto plan real de Azure. Pico de memoria es del proceso completo de PHPUnit, no memoria incremental del reporte. Una medición por tamaño, con efectos de caché; sin extrapolación a producción.

## X. Calidad

Contexto de la regresión fallida del 25/11: una medición de Windows a las 09:16:59 registró 756548 KiB de memoria física libre sobre 8049188 KiB visibles. No existe una medición comparable de recursos de la línea base y no se demuestra causalidad con los fallos de sincronización. Se conserva el dato sin cambiar servicios, cerrar aplicaciones del usuario ni ajustar límites para mejorar las cifras.

Segunda regresión posterior al CSS, 25/11: **900 pruebas / 11592 aserciones**, 46:32.014, 380 MB, salida 1. Fallaron `CheckoutConcurrencyTest@two_keys` y `@same` por ausencia de `holding`, antes de sus aserciones financieras. Se archivó completa. Mejora exclusiva de observabilidad en dos ayudantes y dos workers: fases/PID/tiempos, resultados HTTP y metadatos DMV al faltar la barrera; sin SQL de negocio, tokens ni cambios de esperas/aserciones. Repetición posterior: **12 pruebas / 222 aserciones** de cobro y **13 / 252** de descuentos, ambas con salida 0. No se presenta esta mejora como corrección demostrada de la causa de los fallos intermitentes. El primer filtro combinado contenía un `|` interpretado por el envoltorio Windows: invocación fallida, sustituida por dos filtros separados; no se contabiliza como una ejecución satisfactoria.

La primera regresión posterior al ajuste CSS ejecutó **900 pruebas / 11607 aserciones**, 37:56.270, 380 MB y salida 1. Falló `SaleDiscountsConcurrencyTest` en el caso `two_discounts`: no apareció la señal `holding` dentro de los 15 segundos existentes. El `finally` de la prueba eliminó sus archivos de diagnóstico; no hay evidencia suficiente para atribuir la causa a SQL, autorización o carga del equipo. Repetición aislada sin cambios: **1 prueba / 20 aserciones**, 00:10.963, salida 0. Se conserva el intento fallido y se exige otra regresión completa con las mismas esperas; no se presenta el reintento aislado como aceptación de toda la suite.

Primera regresión de 1.6f: **900 pruebas / 11625 aserciones**, 28:39.800, 380 MB, salida 0. Después se detectó y corrigió el solapamiento visual durante el recorrido nativo, por lo que se ejecutó otra regresión completa sobre el código definitivo.

Regresión definitiva después del ajuste CSS: `composer test -- --log-junit .tools/block05-final-junit.xml`, **900 pruebas / 11625 aserciones**, 43:51.167, 380.00 MB, salida 0. Se conservan nominalmente los 891 casos anteriores, más nueve nuevos; ninguna prueba omitida, fallida o eliminada. Diferencia frente a la línea base real: +9 pruebas y +1008 aserciones. Memoria del proceso completo y tiempos se publican sin confundirlos con el consumo incremental de un reporte.

Validación focalizada previa: **38 pruebas / 1324 aserciones**, salida 0. `composer lint`, `composer analyse` (nivel 5, 426 archivos), `composer validate --strict` y `composer check-platform-reqs`: salida 0 después de los cambios PHP. El formato se corrigió antes de ejecutar estos controles. Cambios posteriores exclusivamente en ayudantes privados, evidencias y documentación.

Comandos desde PowerShell y raíz del repositorio, mediante `.\scripts\local.cmd`. `COMPOSER_PROCESS_TIMEOUT=3600` solo en el proceso, restaurado al terminar; coincide con el presupuesto de 1.6e y no aumenta esperas de SQL ni barreras para ocultar fallos. Se conservan nominalmente los 891 casos anteriores. Larastan nivel 5 sin nuevas exclusiones ni baseline ampliado. Sin SQLite/Docker, commits, pushes, despliegues o cambios de `.env`.

## Y. Defectos

Defecto de seguridad reproducido antes de corregir: contexto de excepción no SQL podía entregar el objeto completo a Monolog, incluidos mensajes o una QueryException envuelta con bindings. Prueba inicial: tres casos, dieciséis aserciones, dos fallos. Corrección mínima en un archivo productivo y regresión de tres variantes con salida serializada real del logger; pasan la focalizada y la regresión completa. Pruebas existentes de API y autenticación fortalecidas sin eliminar comprobaciones.

No se confirmó otro defecto financiero en los escenarios ejecutados. La representación científica de folios en el visor es una limitación del adaptador, documentada con verificación del archivo original. El agregador de carreras asumía erróneamente que todos los casos tenían `LCK_M_*`: la respuesta perdida usa una barrera de intención pending con HTTP409 y `wait_type=null`. Se corrigió únicamente el colector para reflejar esa evidencia, sin alterar la prueba, sus esperas o el resultado financiero.

Defecto visual adicional, severidad baja: nombres largos sin imagen desbordaban la caja alternativa de altura fija y se superponían al título del producto. Reproducción geométrica: nueve tarjetas con fallo en 1440/768/390; 1024 pasaba. Corrección mínima de dos reglas CSS, sin cálculos ni contratos. Regresión automatizada [pos-card-layout.js](../scripts/qa/pos-card-layout.js): cuarenta tarjetas/alternativas en cuatro resoluciones, cero fallos después. Nombre completo accesible y título preservados; no se recorta la información histórica del ticket. La regresión completa se repite tras esta corrección.

Las interrupciones/errores de fixtures o herramientas se distinguen de defectos de negocio. La primera lectura DBCC sin filas produjo «active result contains no fields»; se corrigió únicamente el colector privado para recorrer sus resultsets PDO. No se cambió SQL Server ni se suprimió una infracción.

En el navegador, el primer ayudante podía calcular coordenadas antes de asentarse el cambio de resolución y escribir fuera del campo; se reprodujo que el valor seguía en0 y el foco estaba en otro control. Se añadió comprobación de valor, visibilidad y hit-test antes de cada clic. La repetición completa instrumentada pasó sin respuestas de error y con las auditorías esperadas; no se debilitó autorización. Una doble carga del instrumentador privado producía redeclaración de una constante: se hizo idempotente, sin cambiar JavaScript de la aplicación. Se conservan las capturas iniciales como evidencia del recorrido, separadas de la repetición final del POS.

La primera sonda CSRF de mismo origen recibió200, un caso permitido por el middleware de Laravel13; el formulario nativo desde otro origen local, sin token, sí devuelve419. No se suprime CSRF ni se cambia configuración para conseguir el resultado. Los dos intentos iniciales de volver a descargar tras el cierre no dejaron archivos retenidos: no se cuentan como exportaciones verificadas. Se repite el cierre con fixtures nuevos y retención explícita para ambos formatos y dos reportes.

El colector privado adicional de cierre comparaba el cero PDO con el literal `0.0000`, pero el driver devuelve `.0000`. Un SELECT de constantes de solo lectura lo reproduce y valida la normalización. El primer colector fallido no se presenta como aprobado; sus números se contrastaron con FinancialIntegrity y las capturas (1615.8625/1615.8625/diferencia0). La repetición final del colector normalizado queda documentada con un fixture distinto y sus propios importes. Ningún valor financiero fue ajustado para satisfacer la comparación.

## Z. Pendientes externos

Azure SQL: credenciales autorizadas, TLS/CA/DNS/firewall, aislamiento, permisos de funciones/triggers, planes/volumen y ensayos reales. cPanel: PHP 8.3 del sitio y CLI, pdo_sqlsrv/sqlsrv, ODBC 18 compatible, GD/EXIF/ZIP/mbstring/fuentes/memoria/almacenamiento y reglas de publicación. ionCube: versión/artefacto/compatibilidad reales del proveedor; el proyecto no lo incorpora como dependencia. Scheduler: comando programado y monitorización reales pendientes; `schedule:list` no acredita ejecución automática. Impresora térmica física y cliente Excel de escritorio pendientes. Sin conexiones Azure, instalaciones de controladores, configuración de servicios o despliegues en esta aceptación.

## AA. Conclusión

**ETAPA 1.6f: ACEPTADA FUNCIONALMENTE CON OBSERVACIONES**. Aceptación funcional validada con escenarios integrados, carreras observadas, contratos, archivos e interfaz reales. Integridad histórica: cinco diferencias de valores explicadas con SHA completo reconstruido, ninguna discrepancia financiera material detectada y ninguna modificación de datos operativos durante 1.6f. Esta conclusión local depende de la referencia histórica conservada y no prueba autoría física ni certifica Azure/cPanel/impresión física. Permanece la observación de sincronización de los intentos anteriores: la última regresión pasa y la causa previa no está demostrada. Añadir diagnóstico no equivale a resolverla; se conserva esta reserva de fiabilidad de las pruebas. Ver secciónZ para las verificaciones externas no realizadas.

Se detiene el trabajo en 1.6f. No se avanza a otra etapa ni se realizan commits/pushes.
