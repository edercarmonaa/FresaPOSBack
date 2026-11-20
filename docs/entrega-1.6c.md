# Entrega 1.6c — Reapertura autorizada y corrección segura de ventas pagadas

19 de noviembre de 2026. **ETAPA 1.6c: ACEPTADA LOCALMENTE**. Regresión completa, revisión visual, contrato OpenAPI, respaldo verificado, migración incremental e integridad posteriores satisfactorios. Se admiten correcciones sin diferencia y con cobro adicional explícito; las variantes que requieren devolución o reclasificar pagos históricos permanecen bloqueadas. [Resultados verificables y evidencias](qa/1.6c/README.md).

## A. Diagnóstico

Repositorio existente, rama `main`, HEAD inicial `57b5a31`, árbol limpio. La etapa 1.6b estaba incorporada en ese commit. PHP 8.3.36, Laravel 13.42.0, SQL Server Express `.\AUDITORIA`, autenticación Windows, base operativa `fresapos` y base aislada `fresapos_test`. Se revisaron informes 1.5a, 1.5d.2a–1.5d.5, 1.6a y 1.6b, contratos, pagos, folios, saldo, arqueo, PIN, auditoría, SQL, POS, API, OpenAPI y pruebas concurrentes.

Línea base ejecutada antes de incorporar cambios a la aplicación: **705 pruebas / 8212 aserciones**, **18:19.497**, **198 MB**, exit 0. Pint, Larastan nivel 5 (**374 archivos**), Composer estricto y plataforma correctos. Los componentes nuevos se prepararon primero en una carpeta privada y se incorporaron después de finalizar esa suite. Se capturó una referencia privada de las 27 tablas operativas y hashes de diez archivos protegidos; las 21 comprobaciones financieras iniciales no detectaron violaciones.

Se reutilizan Core/TransactionManager/IdempotentOperations, SalesOperations/ManageSales, SalesStore, SaleTotals, SalesRevision, SaleSnapshotCodec, PaymentAllocation, CheckoutStore, DiscountApproval, motivos autorizados, ActiveCashSession, PaymentMethods, CashMovements, SessionSales, IdentityAccess, Auth/AuthenticatedRequestAccess, PosIntentRegistry y Audit. Sin paquetes nuevos, reconstrucción de módulos ni otro checkout.

## B. Reapertura

`SaleCorrections` es el contrato público; `CorrectPaidSale` coordina el caso de uso. Requiere venta `closed`, ULID/folio/primer cierre/actor de cierre presentes, importes y líneas coherentes, pagos confirmados que sumen el total, sesión Cash original abierta y ninguna corrección activa. Admite cierres previos de total cero con descuento total autorizado y subtotal positivo.

Permisos vigentes: `sales.view`, `sales.reopen`, `sales.special.authorize`, `sales.open.update` y `cash.view`. Confirmar exige además `sales.close`; cambiar descuentos exige `sales.discounts.apply`, también antes de reproducir un recibo. No se conceden permisos operativos: **el rol admin actual es el único que ya incluye `sales.reopen`**. Supervisor, cashier y waiter conservan sus asignaciones anteriores.

Observación opcional, texto plano NFC, espacios normalizados y máximo 150 unidades de almacenamiento Unicode conforme a SalesText; no HTML ni controles. No utiliza ni modifica el catálogo de motivos de cancelación.

## C. Estados

La venta confirmada permanece `closed`, con dinero e historia vigentes. El estado provisional pertenece a su corrección: `active` → `confirmed`, `unchanged` o `discarded`. No se utiliza el estado histórico reservado `reopened`: hacerlo ocuparía nuevamente la mesa y excluiría pagos del cálculo de Cash. Esta representación mínima distingue el borrador sin alterar esas invariantes.

Una corrección activa como máximo por venta mediante índice único filtrado. Solo las rutas específicas de corrección editan el borrador. Checkout ordinario, cancelación ordinaria y edición genérica siguen rechazando la venta pagada. Descartar conserva el borrador en historial y termina su estado temporal; no borra registros ni descarta dinero confirmado.

## D. Persistencia

`sales_corrections`: ULID `nchar(26)`, venta/sesión original, autorizador/UTC de reapertura, observación, estado, instantáneas original/propuesta/resultado, versión entera de revisión, importe adicional confirmado, UTC y actor de finalización. FK reales `NO ACTION`, incluida referencia compuesta a venta/sesión; sin cascadas. JSON válido y coherencia de estados protegidos por CHECK. Índices por sesión/pendientes y venta/fecha/ID.

Las instantáneas son DTOs de Sales: preservan líneas, precios, notas, descuento y pagos necesarios para demostrar cada versión. No contienen modelos serializados, sesiones, contraseñas, tokens o diagnósticos SQL. Los IDs de pagos en las versiones original/final permiten identificar las aplicaciones adicionales sin duplicar filas de pago.

`CorrectionDraftStore` implementa el puerto interno `StagedSalesStore`. Ejecuta las mismas reglas de ManageSales en memoria, dentro de la transacción coordinada, y persiste únicamente el borrador. Su vista interna editable nunca es una segunda venta SQL. El marcador de borrador evita emitir eventos funcionales de campo antes de confirmar.

## E. Folios

No se invoca DailyFolios ni se escribe `sales_daily_sequences`. Se preservan ULID, folio exacto, fecha comercial implícita, creación, primer cierre y primer cerrador, tipo, mesa y sesión original. Cada corrección registra su propia UTC y actor de finalización. Los triggers prohíben alterar esos campos aun durante la aplicación autorizada.

## F. Productos

Agregar, cambiar cantidad, editar nota de línea/general y retirar reutilizan ManageSales, CatalogReader, QuantityLimits, SalesText, Amount y SaleTotals. Máximo global 100 por línea; cantidades enteras reales. Disminuir conserva precio histórico; aumentar exige producto disponible y snapshot vigente; incorporar líneas nuevas usa el snapshot actual de Catalog. No hay edición de precio histórico ni eliminación física de líneas.

Retirar exige motivo activo autorizado de 1.6a y conserva su FK/snapshot. El borrador no escribe evidencia en la línea confirmada. Una retirada solo se aplica al confirmar; las líneas previamente retiradas siguen inmutables. Producto inactivo impide agregar/aumentar, pero no impide corregir la nota histórica. Los conflictos de otro módulo se clasifican mediante el contrato Core existente, sin importar excepciones privadas de Catalog.

## G. Descuentos

Un descuento por venta: porcentaje entero 1–100 o fijo exacto hasta subtotal. Reutiliza autorización, redondeo y cálculo de 1.6b. Porcentaje recalculado sobre consumo; fijo que exceda el nuevo subtotal rechaza la edición sin modificar el borrador anterior. Aplicar/sustituir/quitar exige permiso actual. La confirmación vuelve a validar al autorizador del descuento vigente; revocación impide confirmar.

Los descuentos de borrador no alteran pagos ni generan eventos por campo. Una reducción del total inferior a lo ya pagado permanece bloqueada al confirmar. Un total cero corregido es admisible cuando no requiere devolver dinero, por ejemplo corregir una venta original de total cero manteniendo el descuento total autorizado.

## H. Pagos

Habilitado: cambios sin diferencia monetaria, preservando todos los pagos; incrementos mediante **cobro adicional explícito** que cubra exactamente la diferencia. Usa PaymentAllocation y CheckoutStore existentes, métodos activos, referencias cortas y permiso administrativo vigente para `other`. Efectivo recibido/cambio se calculan con DecimalAmount; solo el importe neto aplicado cuenta como ingreso.

Bloqueado: disminución que requiera devolver dinero, reembolso, devolución, contracargo, cambio de método/referencia de pagos históricos y reclasificación efectivo/tarjeta sin un proceso de evidencia aprobado. No se reduce un pago anterior, no se modifica su actor/fecha ni se crean movimientos negativos o entradas manuales ficticias. Las propuestas inferiores se pueden revisar y descartar, pero no confirmar.

## I. Cash

El saldo continúa calculándose por FinancialStore/ConfirmedCashIncome: fondo + entradas − salidas + efectivo neto confirmado de Sales. El borrador no cambia ese saldo porque la venta confirmada sigue cerrada y sus pagos permanecen vigentes.

SessionSalesSummary incorpora las correcciones activas al listado de pendientes. Cash utiliza su contrato consumidor existente, sin depender de implementaciones de Sales. Reapertura, confirmación y cierre comparten orden de locks: identidad → caja/sesión → venta/corrección. El cierre/arqueo se rechaza transaccionalmente cuando existe una corrección activa. La pantalla explica el bloqueo y enlaza al POS con la venta seleccionada, sin revelar importes en el conteo ciego. Un trigger agrega defensa SQL contra cerrar con correcciones activas.

## J. Confirmación

Core reautoriza, valida sesión/token y bloquea la sesión original y venta. Comprueba revisión de corrección y coincidencia de la historia confirmada con la instantánea de reapertura. Recalcula totales, bloquea devoluciones y valida el pago adicional exacto. Solo entonces aplica cambios permitidos, conserva identidad/primer cierre, añade pagos, comprueba conciliación/saldo, escribe Audit y completa corrección/recibo en el mismo commit.

La ventana SQL de aplicación se identifica por `CURRENT_TRANSACTION_ID()` y la corrección activa; no usa un permiso persistente de sesión ni deshabilita triggers. Se borra el identificador antes de finalizar. Un rollback revierte también esa ventana. La función identifica la transacción de la sesión y puede ejecutarla cualquier usuario; [documentación oficial](https://learn.microsoft.com/en-us/sql/t-sql/functions/current-transaction-id-transact-sql?view=sql-server-ver17). Azure conserva su validación pendiente real.

Si los datos normalizados significativos no cambian, termina `unchanged`: venta/pagos/folio originales y ningún evento «Venta corregida». Descartar termina `discarded`, preservando el original y la propuesta histórica. No existe un cierre financiero parcial ni un nuevo folio.

## K. Auditoría

Un evento `sales.correction.confirmed` («Venta corregida») por corrección efectiva. DTO cerrado SaleCorrectionAudit en SecurityEvent y listener Audit existente: corrección, venta, folio, actor, UTC, sesión Cash, categorías de cambios, totales anterior/posterior e importe adicional. Las versiones detalladas se consultan en Sales; no se duplican textos libres ni secretos en Audit.

No se emiten eventos funcionales por cada edición, por abrir el formulario o por finalizar sin cambios. Replay no duplica. Un fallo de Audit revierte venta, líneas, pagos adicionales, finalización y recibo. SaleChanged posterior al commit es secundario y sus errores no revierten una confirmación ya comprometida.

## L. Idempotencia

Core existente, UUID v4, Idempotency-Key, contexto del actor, hash canónico, exclusión SQL y recibo durable. Reapertura, cada edición, confirmación y descarte tienen intención propia. Misma clave/contenido reproduce resultado; contenido distinto provoca conflicto. If-Match de venta se usa al reabrir; las mutaciones siguientes usan la revisión de la **corrección**. Otra clave no repite una confirmación finalizada.

La Web congela la solicitud y la guarda en sesión del servidor antes de enviarla. Conserva UUID tras respuesta perdida, doble clic, recarga o resultado incierto. Consulta estado sin volver a ejecutar. No renueva automáticamente un UUID enviado con estado pending/not_found. Validaciones locales previas evitan congelar errores de captura no enviados. Sin infraestructura paralela ni almacenamiento financiero en el navegador.

## M. Concurrencia

Dieciséis escenarios con procesos HTTP y conexiones SQL independientes: dos reaperturas, reapertura/cierre, arqueo/reapertura, dos confirmaciones, Checkout ordinario, descuento, productos, cierre posterior, mesa ya reutilizada, misma clave, distinta clave, respuesta perdida, rollback, deadlock real, propuesta que requiere devolución y ausencia de cambios.

Primera ejecución: **16 pruebas / 450 aserciones, 2:32.306, 62 MB**, exit 0. Se observan SPID distintos y esperas LCK_M_*; misma clave obtiene OPERATION_IN_PROGRESS antes del replay. El deadlock produjo 1205 real y Core recuperó la operación. Se verifican original/folio/primer cierre/pagos conservados, pagos adicionales únicos, saldo, historial, Audit y ausencia de ventana SQL pendiente. Los dieciséis escenarios volvieron a pasar dentro de la regresión definitiva; sus dieciséis reportes finales acreditan conexiones distintas y `passed: true`.

## N. Seguridad

Actor de Auth, nunca del payload. Identidad activa, contraseña cambiada y permisos actuales antes de leer/reproducir y bajo lock. AuthenticatedRequestAccess revalida sesión/token dentro de la transacción sin actualizar actividad de acceso. Actor/contexto/venta/UUID/revisión/solicitud de Livewire son Locked. Un snapshot del empleado anterior se rechaza después de cambiar por PIN.

La lectura requiere sales.view y conserva el alcance compartido del establecimiento existente; una línea debe pertenecer a la venta. No se crea un módulo de clientes ni se permite alterar cliente, mesa, folio, fecha de cierre, sesión, importes derivados o autorizadores desde la API. El componente nuevo participa en SensitiveErrors; API mantiene saneamiento con debug true/false. Los permisos adicionales de empleados QA solo existen en `fresapos_test`; no modifican roles operativos.

## O. API

Seis operaciones bajo `/api/v1`, Sanctum/acceso vigente/no-store:

| Método y ruta | Uso |
| --- | --- |
| POST `/sales/{id}/reopen` | Observación opcional, UUID4 e If-Match de venta. |
| GET `/sales/{id}/correction` | Borrador activo; 404 si no existe. |
| PATCH `/sales/{id}/correction` | Un comando cerrado: add, quantity, item-note, note, remove, discount o discount-remove; UUID4 e If-Match de corrección. |
| POST `/sales/{id}/correction/confirm` | Payments adicionales explícitos; lista vacía cuando no hay diferencia; UUID4 y revisión de corrección. |
| POST `/sales/{id}/correction/discard` | Finalización segura del borrador; UUID4 y revisión de corrección. |
| GET `/sales/{id}/corrections` | Historial, page y páginas de diez. |

CorrectionRequest valida campos cerrados, CorrectionController delega y CorrectionResource limita atributos públicos. Resultado: estado de corrección, folio original, versión, actores/UTC, original/propuesta/resultado, resumen y conciliación. `proposed` es un borrador: sus pagos originales no representan un nuevo cobro confirmado. Errores uniformes 400/401/403/404/409/422/500, sin SQL, credenciales ni trazas.

## P. POS Web

Integrado en `/pos`: «Pagadas y correcciones», búsqueda exacta de folio y selección de mostrador/mesa históricas. CorrectionPanel muestra «Corregir venta» únicamente con permisos, observación opcional, borrador, cantidades/notas/productos/motivos/descuento, importes original/propuesto/diferencia, resumen y comparación antes/después.

El incremento exige capturar pagos adicionales, calcular previamente su cambio en servidor y confirmar expresamente. La propuesta de devolución muestra un bloqueo y deshabilita confirmar; el servicio lo exige también ante una llamada directa. Descarte expreso, consulta de intención y recuperación. Las acciones genéricas continúan deshabilitadas para historia pagada. Cash enlaza a la corrección pendiente; la mesa no vuelve a ocuparse.

## Q. Historial

Consulta autorizada y paginación SQL de diez, orden UTC/ID descendente. Conserva reapertura/finalización, actores, observación, folio, totales, diferencia, pagos originales/finales y resultado. Se protege contra actualización y borrado físico; las instantáneas originales también son inmutables mientras el borrador está activo. Sin rutas CRUD genéricas.

## R. SQL Server

Migración incremental única `2026_11_19_000017_enable_paid_sale_corrections.php`. Crea una tabla, restricciones, índices y tres triggers nuevos; adapta de forma acotada las barreras de venta/líneas cerradas para la transacción de corrección. Mantiene inmutables los pagos confirmados, líneas previamente retiradas, precio/nombre/categoría históricos, folio/identidad/primer cierre y toda sesión cerrada. El rollback solo admite bases `_test` y restaura las barreras anteriores para limpiar fixtures.

Despliegue local ejecutado después de pasar la regresión, calidad y OpenAPI. Respaldo `fresapos_pre_1_6c_20261119_205035Z_4a802268.bak`, fuera del repositorio, **10 633 216 bytes**, COPY_ONLY y CHECKSUM confirmados en metadatos. `RESTORE VERIFYONLY WITH CHECKSUM` satisfactorio a las **20:50:36 UTC**; no se afirma una restauración completa de ensayo.

Únicamente se ejecutó `php artisan migrate --path=Modules/Sales/database/migrations/2026_11_19_000017_enable_paid_sale_corrections.php --force --no-interaction` mediante el lanzador local: **120.02 ms**, registro **17**, lote **15**. Resultado: **28 tablas / 17 migraciones**, tabla nueva de **15 columnas**, cinco ULID `nchar(26)`, importe `decimal(19,4)`, cuatro FK confiables `NO ACTION`, CHECK confiable, índice único filtrado y seis triggers financieros inspeccionados activos. Cero correcciones operativas creadas. Las 27 tablas anteriores conservaron sus datos proyectados y las dieciséis migraciones previas.

La primera generación del informe auxiliar posterior a la migración falló al leer un JSON UTF-8 con BOM. Se corrigió únicamente la lectura del archivo privado y se repitieron las verificaciones de solo lectura, todas satisfactorias; no se repitió ni revirtió la migración. [Evidencia del despliegue](qa/1.6c/corrections-deployment-evidence.json).

## S. Calidad

Comandos PowerShell mediante `.\scripts\local.cmd`: composer test (timeout temporal de proceso 1800), composer lint, composer analyse, composer validate --strict y composer check-platform-reqs. SQL/visual se ejecutan secuencialmente y usan únicamente fresapos_test con TestDatabaseGuard; sin SQLite, migrate:fresh o db:wipe.

Resultados intermedios reales: primer grupo SQL **19/128, un error** de conversión de UTC en retirada; se corrigió pasando Carbon UTC tipado al driver. Segundo grupo completo nuevo **34/459, dos fallos**: campo de mensaje en bloqueo Web de devolución y fixture PIN sin sales.create/cash.open. Se corrigieron la presentación/validación Web y únicamente los permisos del empleado ficticio, conservando la política Auth. Repetición Web: **5/71, 48.061 s**, correcta.

Grupo ampliado **63/985, un error**: CatalogConflict por producto inactivo no se traducía en recibo rechazado dentro del servicio. Se utiliza ApiExceptionMappings de Core, sin acceso a clases privadas, y se mantiene el rechazo de Catalog. Repetición específica: **1/4, 6.534 s**, correcta. La revisión visual agregó la prueba número 64 para actualizar el recibo existente después de cobrar la diferencia, preservando UUID y folio.

| Comando mediante el lanzador local | Resultado final real |
| --- | --- |
| `.\scripts\local.cmd composer test` | **769 pruebas / 9216 aserciones**, **22:09.285**, **220.00 MB**, exit 0. |
| `.\scripts\local.cmd composer lint` | Pint correcto, exit 0; repetición confirmatoria también correcta. |
| `.\scripts\local.cmd composer analyse` | Larastan nivel 5, **386 archivos**, cero errores, exit 0. |
| `.\scripts\local.cmd composer validate --strict` | Composer válido, exit 0. |
| `.\scripts\local.cmd composer check-platform-reqs` | Requisitos de plataforma satisfechos, exit 0. |
| `git diff --check` | Sin errores de espacios; avisos de normalización CRLF/LF en documentos. |

Comparación: **705 → 769 pruebas**, **8212 → 9216 aserciones**; las 705 pruebas anteriores se conservan nominalmente y pasan. Se añaden 64 pruebas y 1004 aserciones netas. Tiempo **18:19.497 → 22:09.285** y memoria **198 → 220 MB** al ampliar la suite. Sin nuevas exclusiones, reducción de nivel, baseline de análisis ni dependencias. [Resumen final](qa/1.6c/results.json) e [inventario](qa/1.6c/corrections-inventory.json).

## T. OpenAPI

Documento 3.1 con **70 operaciones**, 31 Sales, 15 Catalog y 13 Cash. La validación contractual inicial y su repetición tras la regresión completa pasaron: **518 respuestas reales / muestras de las 70 operaciones**, cero errores y advertencias. Se conservan las respuestas previas y se añaden muestras nuevas; no se publican exportaciones privadas con tokens QA. Comando final: `powershell -NoProfile -ExecutionPolicy Bypass -File scripts/validate-openapi.ps1 -Responses .tools/corrections-all-responses.json -Report .tools/corrections-openapi-final.json`. [Resultado](qa/1.6c/corrections-openapi-final.json).

## U. Visual

La inicialización de Computer Use y del navegador integrado falló (kernel terminado; sandbox Windows con helper_unknown_error). Se utilizó Chrome local real y el mecanismo CDP existente, con servidor QA 8084, cookies/archivos/perfil separados y fixtures exclusivamente en fresapos_test. Resoluciones 1440×900, 1024×768, 768×1024 y 390×844. **133 capturas reales**: cero desbordamientos horizontales, cero errores de página y cero botones financieros menores de 44 px. Se comprobaron doble clic, pérdida real de respuesta HTTP después del commit, recuperación, bloqueo de devolución, ausencia de cambios, mesa reutilizada, arqueo bloqueado, teclado, PIN, usuario sin permiso especial y sesión revocada.

Hallazgos corregidos: contraste del botón al pasar el cursor, último decimal que saltaba de línea en móvil y recibo de Checkout que conservaba el total anterior tras un cobro adicional. El recibo ahora se consulta nuevamente sin cambiar su UUID ni folio; se agregó una prueba de regresión. Chrome mostró total 540.0000 y tres pagos tras dos incrementos. Las capturas intermedias se conservan identificadas en el [índice de evidencias](qa/1.6c/README.md); las series 32 y 33 demuestran los ajustes finales.

Conciliación visual SQL: seis ventas pagadas y una abierta; seis pagos originales idénticos y dos adicionales, folios y primeros cierres conservados, consecutivo sin incrementos por corrección. Seis correcciones efectivas, dos descartadas y una sin cambios; seis eventos funcionales, saldo 1540.0000, cero movimientos manuales, conciliaciones, operaciones pendientes o ventanas SQL abiertas. Las 28 comprobaciones financieras dieron cero violaciones. El empleado activo por PIN quedó como autor. Navegador y servidor QA cerrados, base de pruebas sin tablas antes de iniciar la regresión.

Una interrupción de herramientas por límite de uso impidió un comando de validación; al reanudar se inspeccionaron logs y procesos antes de continuar. No se considera una validación ejecutada durante ese bloqueo ni un rechazo por riesgo de la acción.

## V. Integridad

Comparación privada proyectada sobre columnas/filas originales de las 27 tablas y dieciséis migraciones previas. Diez archivos protegidos: `.env`, `.env.testing`, database/session/filesystems, Composer JSON/lock, PHPUnit, Larastan y configuración de seguridad Users. No se cambian conexión Windows, credenciales, administrador o asignaciones operativas.

Comparación final satisfactoria: **27 tablas previas y sus datos originales conservados**, dieciséis migraciones anteriores intactas y **10/10 archivos protegidos idénticos**. La sonda financiera mantiene los controles previos y alcanza **28 comprobaciones, todas con cero violaciones**, inspeccionando **64 columnas ULID**. No quedaron ventanas SQL de aplicación pendientes.

**13 comprobaciones HTTP** satisfactorias en `http://127.0.0.1:8080`: inicio, salud, API pública, login, protecciones de administración/Catalog/Sales/Cash/POS y recurso JavaScript de Livewire. Los procesos originales del servidor siguieron activos. `fresapos_test` terminó con **cero tablas**, sin navegador ni servidor QA. No se crearon ventas operativas, reembolsos, movimientos ficticios, commits o pushes. [Integridad financiera](qa/1.6c/corrections-operative-invariants-final.json), [salud HTTP](qa/1.6c/corrections-health.json) y [limpieza](qa/1.6c/corrections-test-database-final.json).

## W. Pendientes

Reclasificación de pagos sin proceso de evidencia aprobado permanece bloqueada. Reembolsos, devoluciones, contracargos, cliente posterior al cierre, traslados/división de mesas, tickets, inventario, recetas, reportes PDF/Excel y Android fuera de alcance. Azure SQL/cPanel no conectados ni desplegados: pendientes reales de PHP/ionCube, pdo_sqlsrv/sqlsrv, ODBC, TLS, firewall, permisos SQL/DDL/triggers y ensayos del proveedor. Scheduler del sistema conserva los pendientes previos; no se configura ninguna tarea Windows.

No hay decisiones pendientes que impidan esta aceptación local. Las verificaciones externas indicadas no se ejecutaron porque no se conectó ni desplegó al proveedor. Las variantes financieras bloqueadas requieren una entrega y proceso aprobados propios. **No se avanza a 1.6d**.
