# Entrega 1.6e — Reportes operativos y financieros

Referencia inicial: 20/11/2026. Retoma: 23/11/2026. **ETAPA 1.6e: ACEPTADA LOCALMENTE**, con la observación de actividad operativa previa descrita en W. No se afirma igualdad absoluta con la referencia del 20/11.

## A. Diagnóstico

Repositorio inicial limpio, main, HEAD a0fc5a5. Se reutilizaron los 13 módulos y las entregas financieras existentes. PHP 8.3.36, Laravel 13.42.0, Livewire 4.4.8 y SQL Server Express 17.0.1155.3. Windows Authentication y America/Mexico_City sin reconfigurar.

Línea base anterior a cambios: composer test, **821 pruebas / 9736 aserciones**, 21:03.090, 240 MB, exit 0. Pint, Larastan nivel 5, Composer validate y plataforma satisfactorios. Comandos mediante .\scripts\local.cmd. Base operativa: 28 tablas, 17 migraciones; SNAPSHOT/RCSI desactivados; 59 columnas de índices financieros inspeccionadas.

El usuario autorizó modificar **composer.json y composer.lock** como excepción a diez archivos protegidos. Dompdf **3.1.6** y PhpSpreadsheet **5.11.0**: once paquetes nuevos, cero actualizaciones/eliminaciones de paquetes existentes, sin avisos de seguridad al instalar. Ocho archivos restantes idénticos. Sin Node/Vite/Docker/colas como requisito productivo.

## B. Arquitectura

OperationalReports comparte datos entre Web/API/PDF/XLSX. ReportCriteria valida filtros/fechas; ReportDefinition delimita columnas/orden; ReportData transporta escalares. QueryOperationalReports coordina lectura consistente, autorización, fechas y nombres. ExportOperationalReport renderiza, reautoriza y audita.

SalesReports/CashReports son contratos públicos con consultas en sus propietarios. IdentityLabels resuelve nombres en lotes de 500 desde Users. Core aporta ReportQuery, ReportSlice, ReportExportAudit y ApiDownloadResponse. Reports no consulta tablas/modelos privados ajenos. Dependencias acíclicas Reports → Core/Sales/Cash/Users/Auth. Sin tablas, migraciones o roles nuevos.

Proveedores existentes registran implementaciones. Navegación integrada en layout sin sustituir POS/Cash/Tickets/Livewire. Core conserva descargas binarias explícitas y mantiene JSON común para los demás éxitos y errores.

## C. Ventas

Una fila por venta. Pagadas por primer cierre y versión financiera confirmada vigente, conservando venta/folio. Abiertas/pausadas/canceladas pueden consultarse por creación/estado, sin sumar ingresos. Tipo, fecha comercial, mesa, empleado, subtotal, descuento, total y señales de corrección.

Reporte operativo del estado confirmado, no libro contable por fecha de movimiento. Una corrección posterior actualiza la venta en el período de su primer cierre; el pago adicional puede tener otra fecha. No se inventa una reclasificación contable.

## D. Productos

Unidades e importe bruto de líneas vigentes confirmadas, con precio/nombre/categoría históricos de Sales. Sin precios actuales de Catalog ni prorrateo artificial del descuento general. Agrupación exacta BIN2 por producto/categoría/nombres; versiones distintas no se fusionan. Participación sobre bruto filtrado, presentada a cuatro decimales.

Renombrar/cambiar precios en fixtures conserva el pasado. QA incluye 26 productos con nombres largos y PDF de cinco páginas.

## E. Métodos de pago

Pagos confirmados e importe aplicado; recibido/cambio no son ingreso adicional. Efectivo, tarjeta, transferencia y otro autorizado. Método selecciona ventas que lo utilizaron y conserva todos sus componentes mixtos para conciliar, explicado en interfaz/notas.

Diferencia = total confirmado seleccionado menos pagos aplicados seleccionados. Total cero no inventa pagos. Sin referencias bancarias privadas, recibido o cambio individual. Adicionales de corrección pertenecen a la misma venta.

## F. Movimientos

Entradas/salidas manuales separadas de ventas. Importe positivo, naturaleza en tipo, neto = entradas − salidas. Motivo, actor, fecha y sesión. Si falta otra referencia se conserva el ID del movimiento, sin inventar una. Sin reclasificar como gastos contables o ingresos de ventas.

## G. Sesiones Cash

Una fila por turno, incluso varios el mismo día. Filtro por apertura y empleado que abrió. Fondo, fechas, actores y estado; nombre de caja persistido en conciliación con prioridad sobre el actual. Esperado/contado/diferencia siempre null mientras esté abierta, incluso para administradores y exportaciones.

## H. Arqueos

Solo conciliaciones confirmadas por fecha de confirmación y empleado que contó/cerró. Fondo, entradas, salidas, efectivo neto, esperado, contado, diferencia firmada y observación persistidos; sin recalcular pasado. SQL cubre cero/faltante/sobrante.

QA comprobó cierre y nuevo turno. Primero se rechazó el cierre por ventas abiertas/pausadas/corrección activa; se resolvieron mediante servicios existentes en el fixture. No se debilitó Cash para generar capturas.

## I. Descuentos

Descuento vigente confirmado de ventas pagadas, fijo/porcentaje, valor autorizado, importe, subtotal/total, actor y autorizador almacenado. Canceladas excluidas. Total cero con folio y sin pagos. Audit/Sales conserva cada intento de modificación; este reporte presenta la versión confirmada.

## J. Correcciones

Historial por finalización o reapertura si activa, separado del primer cierre. Total anterior del snapshot original; posterior/adicional solo confirmadas o sin cambios. Activas/descartadas sin importes provisionales ni aumento de ingresos. No selecciona draft_snapshot. Venta/folio/pagos originales conservados; autorización reforzada.

## K. Indicadores

| Indicador | Definición |
| --- | --- |
| Ventas pagadas | Cerradas con folio por primer cierre, incluidas cero |
| Ingreso | SUM del total confirmado vigente |
| Subtotal/descuento | SUM de importes confirmados |
| Ticket promedio | AVG de todas las pagadas; null sin ventas |
| Promedio descuento | AVG de descuentos mayores a cero; null sin denominador |
| Unidades | SUM de cantidades vigentes confirmadas |
| Métodos | SUM aplicado por código, incluida corrección de la misma venta |
| Cierres/diferencias | Conciliación persistida por fecha de cierre |
| Correcciones | Cantidad confirmada y adicional, no otra venta |

SUM usa decimal ampliado; AVG/porcentajes se presentan a cuatro posiciones. Sin float para dinero. Se normaliza el cero anterior al punto omitido por PDO_SQLSRV en decimal(38,4). Cantidades enteras; promedio inexistente no convertido a cero. Serie diaria con límites UTC y zona comercial.

## L. Filtros

Hoy, ayer, siete días, mes actual hasta hoy, mes anterior y personalizado. Fechas locales inclusivas → UTC final exclusivo, sin funciones sobre fechas del WHERE. Máximo 366 días, años datetime 1753–9998. America/Mexico_City mapea a Central Standard Time (Mexico); UTC también está contemplado. Otra zona requiere mapeo probado.

Empleado, tipo/estado, método, sesión, producto/categoría y descuento/movimiento según reporte. ULID normalizados, desconocidos/no aplicables rechazados; orden cerrado con desempate estable. Páginas 10/25/50/100. Presets devueltos como fechas explícitas para no variar a medianoche. Exportar toma el conjunto completo, independientemente de página.

Producto/categoría seleccionan ventas para totales y líneas correspondientes para ranking, sin prorrateo. Cierres del resumen conservan su fecha y filtros aplicables de actor/sesión; producto/método/descuento no recalculan conciliaciones. Notas visibles explican la diferencia.

## M. PDF

Dompdf sin PHP/JavaScript/recursos remotos; Blade escapado, DejaVu Sans, A4/A3 horizontal según columnas, máximo **300 filas**, encabezados repetidos y páginas numeradas. Render fuera de locks, entrega en memoria sin archivo público persistido.

**10 PDF / 15 páginas** de muestras finales: nueve reportes y un cierre adicional. Se validaron veinte descargas en Chrome y se regeneraron veinte por HTTP API real después del ajuste final de Excel. Pypdf/Pdfplumber comprobaron contenido/márgenes; Poppler renderizó todas las páginas y se inspeccionaron. [Evidencias](qa/1.6e/README.md).

## N. Excel

XLSX real, una hoja Reporte, sin macros/fórmulas. Nombres/folios/IDs como texto explícito, incluidos = + − @. Fechas seriales, cantidades/montos numéricos, formatos de cuatro posiciones, filtros, ajuste, anchos y encabezado congelado. Indicadores en tres parejas por fila y notas debajo del detalle; el filtro excluye notas. Las muestras tienen como máximo 337 puntos de área congelada.

Máximo **1000 filas**; más de once enteros monetarios o quince enteros de conteo se rechazan por precisión, incluidos totales; sin redondeo silencioso. tmpfile privado se cierra al terminar; archivos en docs solo de fixtures.

**10 XLSX** finales descargados por HTTP real y reabiertos con PhpSpreadsheet/Openpyxl, tipos/fechas/fórmulas/macros comprobados; todas las hojas renderizadas. Caso de doce ventas comprueba exportación completa desde página 2/tamaño 10.

## O. Seguridad

Permisos reports.view y reports.export existentes, sin cambios de roles. Consolidación exige cash.administrative.execute para evitar acceso indirecto al arqueo ciego. Sales requiere sales.view; Cash, cash.view y salvo movimientos cash.closings.view; resumen combina ambos; correcciones añade sales.reopen y sales.special.authorize. Sin bypass por rol.

Auth/Sanctum existentes, activo/cambio obligatorio, sesión/token vigente y actor actual tras PIN. Reautoriza permisos propietarios antes de entregar si cambian durante render. Sin SQL/rutas/secretos/trazas con debug activo/inactivo. 401/403/422/500 uniformes y éxito binario explícito.

## P. Auditoría

Un reports.export.generated por generación: actor, UTC, request ID/canal, reporte/formato, filtros limitados, filas/huella; sin contenido completo. deliveryConfirmed=false: generado no confirma recepción/impresión. Otra petición que regenera es otra generación; no se afirma idempotencia durable ni un segundo evento duplicado de descarga.

Audit posterior a render/reautorización; su fallo impide éxito y revierte el evento sin alterar finanzas. Navegador: 20 generaciones, 18 iniciales y dos de cierre, muestras separadas. Tokens/cuerpos autenticados y valores operativos completos privados.

## Q. API

GET /api/v1/reports/{report}; POST /api/v1/reports/{report}/exports/{format}. Nueve nombres/dos formatos cerrados, FormRequest, DTO y Resource. JSON común para consultas/errores; archivos con Content-Disposition, Content-Length, nosniff y private/no-store. Exceso/precisión: 422; contención SQL: 503. [Contrato](openapi.yaml), [API](api.md).

## R. Web

/reports integra navegación, filtros, indicadores, serie diaria, tabla/tarjetas responsivas, páginas y exportaciones. Se corrigieron palabras partidas en columnas de ventas. Controles Reports de 44 px y foco visible; enlaces POS/Cash autorizados por destino.

Login, filtros/páginas, vacío, errores, rol denegado y descargas probados por navegador. 8080: 20 rutas/recursos revisados, incluidos salud/ping/login/POS/Cash/Tickets/Reports/JavaScript Livewire, sin sesión operativa ni escrituras de negocio.

## S. SQL Server

Parametrización, agregación/paginación SQL y ausencia de NOLOCK. Una transacción SNAPSHOT si ya está habilitado, SERIALIZABLE en la instancia local. SET como lote directo dentro de BEGIN antes de leer: la primera carrera mostró que la forma preparada no conservaba el aislamiento efectivo. Sin activar SNAPSHOT/RCSI globalmente.

Deadlock LOW, espera de bloqueo cinco segundos y restauración de opciones en finally. 1205/1222 cancelan con 503 sin datos parciales; render fuera de locks. La carrera de SPID distintos observó LCK_M_X; se verifica lectura coherente o cancelación segura y consulta posterior con ingreso 360/unidades 2. No se promete ausencia de contención o límite de CPU total de cinco segundos. [Resultado](qa/1.6e/concurrency.json).

El capturador inicial no encontró de forma estable el plan en caché y produjo el único error de la primera regresión. Se sustituyó por **SHOWPLAN_XML de una consulta equivalente con literales escapados por PDO, en modo directo y exclusivamente en fresapos_test**. Es un plan estimado: no se presenta como plan real del parametrizado de producción. El modo directo y SHOWPLAN se restauran en finally. Las mediciones sí ejecutan la consulta parametrizada del servicio.

Benchmark final con 1001 ventas homogéneas creadas por los servicios reales (ventas, líneas, pagos, folios y auditoría), sin tocar datos operativos:

| Página | Sentencias | Tiempo medido |
| --- | --- | --- |
| 10 filas | 26 | 120.092 ms |
| 100 filas | 26 | 63.116 ms |

Totales de todas las 1001 ventas: 180180.0000, 1001 pagadas. Páginas sin IDs repetidos y orden repetible; las exportaciones de más de 1000 filas se rechazan. Las 26 sentencias incluyen seguridad, metadatos y restauración: no crecen de 10 a 100 filas; nombres por lotes, sin N+1. Pico global de PHPUnit 102 MiB, incluyendo fixtures, no memoria incremental del reporte. Medición única y orden/caché diferentes; no se deduce que 100 filas sean intrínsecamente más rápidas. No se extrapola a producción o distribuciones distintas.

Sin índices o migraciones nuevos: el volumen local medido no justificó una modificación de esquema. Plan privado conservado y resumen saneado en [performance.json](qa/1.6e/performance.json). Faltan estadísticas/volúmenes y planes de Azure/hosting reales.

Fuentes primarias: [driver directo/preparado](https://learn.microsoft.com/en-us/sql/connect/php/direct-statement-execution-prepared-statement-execution-pdo-sqlsrv-driver), [deadlock](https://learn.microsoft.com/sql/t-sql/statements/set-deadlock-priority-transact-sql), [LOCK_TIMEOUT](https://learn.microsoft.com/en-us/sql/t-sql/statements/set-lock-timeout-transact-sql).

## T. Calidad

70 casos nuevos, **891 casos únicos**, ninguno de los 821 anteriores eliminado. ReportCriteriaTest, OperationalReportsTest, ReportExportsTest, ReportsHttpTest, ReportsConcurrencyTest y ReportsPerformanceTest, más soporte/trabajador SQL exclusivos de pruebas. Escrituras solo en fresapos_test y TestDatabaseGuard; fixtures visuales limpiados antes de las suites y cero tablas al finalizar.

| Ejecución | Comando mediante scripts/local.cmd | Resultado real |
| --- | --- | --- |
| Línea base | composer test | 821 pruebas / 9736 aserciones; 21:03.090; 240 MB; exit 0 |
| Primera regresión | composer test | 891 / 10611; 29:32.710; 274 MB; un error en capturador de plan, exit 2 |
| Regresión corregida completa | composer test -- --order-by=defects --stop-on-error | **891 / 10615**; 25:01.075; 276.00 MB; exit 0 |
| Repetición final Reports | php vendor/phpunit/phpunit/phpunit y los seis archivos de Reports | **70 / 868**; 10:20.682; 102.00 MB; exit 0 |
| Formato | composer lint | exit 0 |
| Análisis | composer analyse | Larastan nivel 5, 425 archivos, exit 0 |
| Dependencias | composer validate | exit 0 |
| Plataforma | composer check-platform-reqs | exit 0 |

La repetición focalizada valida también el último ajuste ISO 8601 UTC de created_at/closed_at; no se suma a 891 para inventar más casos únicos. Las columnas comerciales mantienen offset y compañero _utc. [Resultados](qa/1.6e/quality.json).

Fallos detectados/corregidos: texto de fecha SQL con seis fracciones, cero decimal del driver, foreach Blade, conversión binaria a JSON, respuesta Symfony sin métodos Laravel, SET preparado sin aislamiento efectivo, capturador de planes y tres detalles de Pint. Se repitieron casos afectados. Sin supresiones ni ampliación del baseline de Larastan. No quedan pruebas locales requeridas sin ejecutar.

No ejecutadas: impresora física, Excel de escritorio y despliegues/compatibilidad de Azure SQL/cPanel/ionCube/ODBC, fuera de aceptación local.

## U. OpenAPI

**74 operaciones reales**, dos nuevas; validación formal 3.1 y comprobación bearer/auth.access.api extendida a Reports. Contraste final: **609 respuestas / 74 operaciones**, cero errores y advertencias. Respuestas anteriores conservadas; éxitos binarios verificados como archivos, no JSON inventado. Los cuerpos autenticados permanecen privados. [Resultado final](qa/1.6e/openapi-final.json).

## V. Visual

**102 capturas finales**: nueve reportes × encabezado/resultados × cuatro tamaños = 72; más 30 de páginas, vacío, error, filtro, denegado, cierre, dos turnos, navegación/teclado. 1440×900, 1024×768, 768×1024, 390×844. Sin desbordamiento horizontal/errores JavaScript. Chrome contra QA 8085/datos ficticios.

Navegador integrado falló dos veces con windows sandbox failed: helper_unknown_error; alternativa Chrome/CDP, sin dejar pendiente la revisión. Chrome/QA cerrados, 8080 conservado. Diez PDF/15 páginas y diez XLSX finales inspeccionados, con fechas UTC contrastadas contra el archivo real; el visor utiliza el rango explícito de cada hoja. Las muestras finales se regeneraron por API HTTP real después del ajuste de Excel; las capturas corresponden al escenario visual independiente de Chrome. Impresora/clientes externos pendientes.

## W. Integridad

Ocho archivos con hash idéntico: .env, .env.testing, config/database.php, config/session.php, config/filesystems.php, phpunit.xml, phpstan.neon y Modules/Users/config/security.php. Composer: excepción autorizada. Sin migraciones operativas/fresh/wipe/commits/pushes/despliegues. **28 invariantes/64 columnas ULID operativos: cero violaciones**. Lecturas/exportaciones QA iniciales conservaron hashes financieros.

Comparación con 20/11 no idéntica en cinco tablas: Audit 68→71, acceso 15→15 con cambios, conciliaciones 2→3, sesiones Cash 3→4, idempotencia 17→18. Audit registra cierre/arqueo **20/11 17:27:55 UTC** y apertura **17:28:30 UTC**, después de referencia 17:22:44 y antes de retoma del 23. Otras 23 tablas, incluidas ventas/líneas/pagos/folios, idénticas. No se atribuye actividad a una persona sin evidencia ni se revierte para forzar igualdad. No se afirma igualdad absoluta de toda la base.

Comparación final ejecutada: las mismas cinco tablas presentan diferencias frente al 20/11; las otras 23 permanecen idénticas. No hubo migraciones o escrituras de negocio operativas en esta entrega; se conservó la actividad existente. Las comprobaciones operativas fueron de lectura. [Resumen de integridad](qa/1.6e/integrity.json), [protección e inventario](qa/1.6e/protected-and-tests.json), [salud](qa/1.6e/health.json).

## X. Pendientes

No quedan comprobaciones locales requeridas pendientes. Fuera de validación: Azure SQL, cPanel/PHP 8.3/ionCube, disponibilidad de pdo_sqlsrv/sqlsrv/ODBC del hosting, firewall/TLS, fuentes/memoria, impresora física, Excel de escritorio y Scheduler. Sin despliegue, conexión Azure o avance a 1.6f.

Límites: PDF 300 filas, XLSX 1000, precisión Excel, posible 503 con SNAPSHOT desactivado, nombres de empleado/mesa actuales si no hay snapshot, filtros por identificador exacto y mapeo para otra zona. El rango de primer cierre muestra el estado financiero confirmado vigente, no una contabilidad por fecha de cada movimiento. Los resultados locales no certifican volumen real de producción.

Observación operativa W conservada expresamente: no se afirma igualdad total contra el 20/11. Composer ya autorizado; no hay otra decisión de implementación solicitada. Sin commits, pushes o despliegues. Se detiene el trabajo en 1.6e.
