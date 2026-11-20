# Entrega 1.6d — Tickets térmicos de 80 mm

20 de noviembre de 2026. **ETAPA 1.6d: ACEPTADA LOCALMENTE**. Revisión visual e impresión desde navegador verificadas; impresora física no comprobada. Este documento distingue resultados ejecutados de pendientes.

## A. Diagnóstico inicial

Repositorio existente, rama `main`, HEAD `57b5a31`. Los cambios aceptados de 1.6c permanecían sin commit y se conservaron. Se inspeccionaron los informes de checkout, Cash, conciliación, motivos, descuentos y correcciones; contratos, proveedores, DTOs, permisos, auditoría, configuración, POS, rutas, pruebas y OpenAPI.

Línea base ejecutada antes de incorporar código: **769 pruebas / 9216 aserciones**, **25:13.700**, **220 MB**, exit 0. Pint, Larastan nivel 5, Composer y plataforma correctos. Durante esa ejecución los cambios se prepararon en una carpeta privada fuera del autoload; Git mantuvo el mismo estado hasta finalizar la línea base. Se capturaron referencias de las **28 tablas operativas** y los diez archivos protegidos.

Tickets ya existía y registraba proveedores, vistas y rutas, pero no tenía casos de uso. Existían `tickets.view` y `tickets.print`; no se modifican roles, permisos, credenciales ni administrador. No había datos empresariales configurados, datos de clientes persistidos, infraestructura de impresoras, librerías de impresión o estilos térmicos que reutilizar.

## B. Arquitectura

`Sales/ConfirmedSalesDocuments` proporciona una instantánea coherente mediante `ReadConfirmedSaleDocument`: venta bloqueada, cierre/folio válidos, pagos confirmados conciliados, totales históricos consistentes y ausencia de corrección activa. Reutiliza SalesStore, SaleTotals, SalesRevision y CorrectionStore. No consulta Catalog para construir el comprobante ni invoca checkout o DailyFolios.

Tickets consume únicamente contratos públicos de Sales, Users, Auth y Core. Esta ampliación explícita del grafo permite leer datos confirmados y revalidar acceso sin ciclos ni acceso a modelos privados. No se crea dependencia Sales → Tickets: el POS integra una ruta Web. Los pagos confirmados ya pertenecen al contrato histórico de Sales; Tickets no necesita consultar internamente Cash.

`SaleReceipts` se implementa mediante `GenerateSaleReceipt`. `ReceiptData` es un DTO readonly independiente de HTML, con identificadores del documento, fechas, productos, importes decimales, pagos saneados y metadatos opcionales. Las lecturas y solicitudes usan transacciones coordinadas de Core y el orden identidad → venta/corrección. El bloqueo garantiza una versión consistente; no constituye una mutación financiera.

Archivos principales añadidos: contratos `ConfirmedSaleDocument`/`ConfirmedSalesDocuments` y servicio `ReadConfirmedSaleDocument` de Sales; contratos `ReceiptData`/`SaleReceipts`, servicio `GenerateSaleReceipt`, `ReceiptController`, rutas API y vistas `receipt`/`error` de Tickets; contrato `ReceiptAudit` de Core. Se ampliaron los proveedores de Sales/Tickets, `CorrectionStore::latestConfirmed`, la lista cerrada de SecurityEvent/Audit, la configuración opcional de Tickets y el enlace de la vista POS. El grafo se registra en `config/module-dependencies.php`.

Pruebas nuevas: `SaleReceiptsTest`, `SaleReceiptsHttpTest`, `SaleReceiptsWebTest`, `SaleReceiptsConcurrencyTest` y soporte `ReceiptsSqlServerTestCase`. Se actualizaron los inventarios de rutas/controladores de las pruebas existentes. Documentación modificada: README, CHANGELOG, arquitectura, API, OpenAPI, este informe e índice QA. Los cambios previos de 1.6c se conservaron; no se atribuyen a esta entrega ni se vuelven a aplicar sus migraciones operativas.

## C. Formato

Vista Blade independiente: papel nominal **80 mm**, presentación de pantalla de 80 mm con márgenes interiores y contenido impreso de **74 mm**, limitado al ancho disponible. Fondo blanco, texto negro, tipografía de sistema, importes sin salto de línea y nombres/notas con ajuste de palabras. Se conservan cuatro decimales para representar exactamente los importes existentes.

Altura natural; sin altura fija o recorte de contenido. Las líneas evitan fragmentarse cuando caben en la página. El motor puede paginar tickets largos según el papel seleccionado. No hay tablas anchas, imágenes, navegación administrativa o controles dentro del área imprimible.

## D. Datos del ticket

Folio, primer cierre real, tipo de venta, nombres/precios/cantidades/notas históricos, subtotal, descuento confirmado y total. Las líneas retiradas se omiten del consumo vigente; permanecen en Sales. Cada pago se presenta por separado, con su importe aplicado y, únicamente en efectivo, recibido y cambio históricos. No se recalcula el ingreso a partir del recibido.

Se omiten referencias bancarias, IDs de pagos/métodos, costos, permisos, credenciales y autorizadores de descuentos. No existe información de cliente en el modelo actual y no se crea una asociación ficticia. El empleado se resuelve desde `closed_by`, conservado por Sales: se presenta el nombre disponible de esa identidad; no se afirma disponer de una instantánea histórica del nombre. Si la identidad no está disponible, se omite. Tampoco se inventa un nombre histórico de mesa que Sales no conserva como snapshot.

Total cero: productos, subtotal, descuento, folio, cierre y total, sin pagos/efectivo/cambio ficticios. La leyenda indica descuento total autorizado sin cobro.

## E. Folios

Se lee el folio persistido `YYYYMMDD0001`; no se genera, recalcula ni consume consecutivo. El ULID permanece fuera del papel; la API utiliza el identificador para la consulta autorizada. La fecha de cierre se conserva en UTC y se convierte exclusivamente para presentación mediante `pos.business_timezone`, actualmente America/Mexico_City. No se utiliza el reloj del navegador.

## F. Ventas corregidas

Política: **no se emite comprobante definitivo mientras exista una corrección activa**. API y Web responden con conflicto. El estado financiero anterior permanece conservado en Sales; el borrador nunca se presenta como venta definitiva.

Después de confirmar se lee la última versión completa: líneas, descuento y todos los pagos confirmados originales/adicionales. Se conserva folio y primer cierre; se indica que hubo corrección y su fecha separada. Una vista previa antigua no autoriza emitir una versión obsoleta: se verifica su revisión incluso antes de reproducir una intención completada. Las versiones anteriores siguen consultables mediante el historial existente de 1.6c; no se crea otro sistema de versionado.

## G. Impresión

`@media print` oculta controles y usa el área útil; `@page` declara `size: auto` y margen de 3 mm. El usuario selecciona papel de 80 mm, escala 100 % y configura encabezados/pies del navegador. No se usa `size: 80mm auto`: esa combinación no forma parte de la sintaxis del descriptor; [referencia de `size`](https://developer.mozilla.org/en-US/docs/Web/CSS/Reference/At-rules/%40page/size) y [guía de impresión](https://developer.mozilla.org/en-US/docs/Web/CSS/Guides/Media_queries/Printing).

La carga de la vista no dispara impresión. Solo el botón explícito del documento preparado llama `window.print()`. No hay impresión silenciosa, selección automática de dispositivo, ESC/POS, instalación de controladores ni servicio privilegiado. CSS no puede confirmar la salida física ni controlar todas las opciones del controlador.

## H. Reimpresión

Consulta con `sales.view` y `tickets.view`; preparación adicional con `tickets.print`. La vista previa genera una intención cifrada de servidor, ligada a actor, venta, revisión y UUID v4, con vigencia de quince minutos. El POST requiere CSRF y revalida acceso, permisos y versión dentro de la transacción. Cambiar de empleado por PIN invalida la intención anterior.

Core conserva el resultado durable: misma intención/contenido reproduce el documento sin duplicar auditoría. Una intención nueva representa otra solicitud explícita. No se inventa un número de impresiones ni se intenta determinar una primera impresión física. Toda preparación autorizada se identifica como **REIMPRESIÓN · COPIA AUTORIZADA**; la consulta ordinaria se identifica como **VISTA PREVIA**.

Audit recibe `tickets.reprint.requested` con venta, folio, actor, UTC, revisión y acción de documento preparado. `physicalPrintConfirmed` es siempre false. El evento acredita una solicitud procesada y datos del comprobante preparados, no entrega de papel ni éxito de una impresora. No duplica el checkout. Un fallo auxiliar revierte esa solicitud y su auditoría, conservando la venta ya pagada.

## I. Seguridad

Autenticación Web/Sanctum y sesiones vigentes de Auth. Autorización actual antes de consultar y bajo lock; revocación comprobada sin actualizar actividad dentro del bloqueo. El alcance horizontal conserva la política compartida del establecimiento existente: conocer un ULID o folio no concede acceso, y son obligatorios ambos permisos de lectura.

No se aceptan precios, totales, empleado, cliente o datos bancarios para construir un ticket. La intención cifrada protege el contexto del empleado y la versión; no contiene valores financieros aportados por el navegador. Texto escapado por Blade. API mantiene sus errores uniformes; los errores Web del comprobante se sanean también con debug activo. Respuestas privadas no-store.

No es posible impedir que alguien con acceso de lectura fotografíe, copie o imprima la vista mediante funciones propias del navegador. El permiso controla la preparación autorizada y auditada del comprobante, y la vista ordinaria conserva su marca de vista previa.

## J. API

| Método y ruta | Resultado |
| --- | --- |
| GET `/api/v1/sales/{id}/receipt` | DTO seguro del comprobante confirmado. |
| GET `/api/v1/sales/folio/{folio}/receipt` | Mismo DTO mediante folio real. |

Sin parámetros financieros ni mutaciones. ULID/folio inválidos o inexistentes: 404; acceso ausente/revocado: 401; permisos insuficientes: 403; cierre no elegible/corrección activa: 409; campos no admitidos: 422. Errores internos saneados. Establecimiento ausente se serializa como null. No se añade una API de impresión física.

## K. POS Web

En el detalle pagado, incluido el resultado del checkout y «Pagadas y correcciones», se ofrece «Ver ticket / preparar impresión». No se ofrece ticket definitivo para ventas abiertas. El enlace abre una vista sin navegación administrativa, con regreso al POS y la venta seleccionada.

GET `/pos/sales/{id}/receipt`: vista previa autorizada. POST `/pos/sales/{id}/receipt/print`: solicitud explícita con auditoría; devuelve copia preparada y botón de impresión del navegador. Solo los usuarios autorizados ven la acción de preparación. No se reconstruye el panel POS.

## L. Configuración

Parámetros no sensibles en `Modules/Tickets/config/config.php`: nombre comercial, sucursal, dirección, teléfono, identificador fiscal y pie opcional; todos vacíos inicialmente. Identificador fiscal requiere además `print_fiscal_id = true`. No se utiliza APP_NAME como razón social ni se inventan datos empresariales. No se crea una administración de sucursales ni se modifica `.env`.

## M. Persistencia

**Sin migraciones, tablas ni dependencias nuevas.** Se reutilizan Audit y los recibos durables de Core. No se requiere respaldo o migración adicional por esta entrega. El esquema conserva 28 tablas y 17 migraciones; registros previos conservados y actividad Web adicional explicada en Q. No se escriben ventas, pagos, secuencias, descuentos, sesiones Cash, movimientos o arqueos al generar comprobantes.

## N. Calidad

Comandos de referencia, ejecutados con el PHP local mediante PowerShell:

```powershell
.\scripts\local.cmd composer test
.\scripts\local.cmd composer lint
.\scripts\local.cmd composer analyse
.\scripts\local.cmd composer validate
.\scripts\local.cmd composer check-platform-reqs
```

Resultado final: **821 pruebas / 9736 aserciones**, **19:13.303**, **240.00 MB**, exit 0, sin fallos ni avisos. Se conservan las 769 pruebas anteriores y se añaden 52. [Resumen ejecutado](qa/1.6d/tests-summary.json). Para esa ejecución se amplió únicamente la variable del proceso `COMPOSER_PROCESS_TIMEOUT` a 3600 y se restauró al terminar; no cambió composer.json.

Resultados intermedios conservados:

- La primera ejecución focalizada y el primer análisis se interrumpieron sin resultado completo; no se consideran aprobados. Los fixtures se identificaron y limpiaron exclusivamente en fresapos_test con TestDatabaseGuard y los métodos down existentes.
- La ejecución reanudada de 51 casos produjo 489 aserciones, un error, un fallo y cuatro avisos. Se corrigieron dos fixtures: observación obligatoria del cierre Cash y notas únicas para evitar la fusión legítima de productos repetidos. Se sustituyeron mocks sin expectativas por stubs. No se relajaron reglas financieras.
- Larastan señaló cinco errores de tipado/capturas de cierres y acceso redundante. Se corrigieron compartiendo un estado tipado entre callbacks y eliminando el acceso redundante, sin ignorar errores.
- La segunda ejecución focalizada pasó: **51 pruebas / 500 aserciones**, **1:49.281**, **88 MB**, sin fallos ni avisos.
- Durante la revisión se encontró un ULID en mayúsculas cuya intención no coincidía con la URL normalizada. Una prueba adicional reprodujo el 403: **1 prueba / 7 aserciones / 1 fallo**. Se normalizaron el identificador del controlador y el recurso idempotente. La misma prueba pasó después: **1 prueba / 10 aserciones**, **3.753 s**, **62 MB**; las variantes de URL reproducen una sola auditoría.
- La regresión anterior de 820 casos fue detenida a los 441 casos para incorporar esta corrección; no se declara aprobada. Se reinició una regresión completa de 821 casos.
- La siguiente pasada completa terminó con **821 pruebas / 9722 aserciones**, **25:09.482**, **240 MB**, exit 1: un trabajador del caso histórico `CashConcurrencyTest` (`transition`) no produjo su señal `holding` dentro de los doce segundos disponibles. No alcanzó las aserciones financieras de ese caso. Las 52 pruebas nuevas de Tickets pasaron. La clase Cash completa pasó inmediatamente en aislamiento sin modificar código: **6 pruebas / 92 aserciones**, **12.953 s**.
- Se amplió únicamente la coordinación del arnés Cash: disponibilidad del trabajador de 12 a 30 s, límite total del proceso de 35 a 90 s y espera del trabajador por el coordinador de 20 a 60 s. Se añadió un mensaje explícito al fallo de disponibilidad. No se alteraron los seis segundos para observar el bloqueo SQL ni sus aserciones financieras, trabajadores independientes o lógica productiva. Se modificaron `tests/Integration/CashConcurrencyTest.php` y `tests/Fixtures/Cash/concurrent.php`; ambos son soporte de pruebas existente. La clase volvió a pasar con el ajuste: **6 pruebas / 92 aserciones**, **12.016 s**, **64 MB**. La regresión completa posterior pasó sobre ese estado final, como se consigna al inicio de esta sección.
- Pint pidió ordenar imports en el nuevo caso; se aplicó el formato y la comprobación posterior pasó. Larastan nivel 5 comprobó **397 archivos sin errores**. Composer validate y check-platform-reqs terminaron con exit 0.

No se eliminan pruebas anteriores ni se reduce el nivel de análisis. El inventario y los códigos de salida están en [evidencias QA](qa/1.6d/README.md).

## O. OpenAPI

**72 operaciones**: las 70 anteriores y dos consultas de comprobantes. La validación formal y la correspondencia con rutas reales pasaron sin errores ni advertencias. La primera validación de respuestas reales pasó con **552 respuestas / 72 operaciones**, sin errores ni advertencias. Validación final después de la regresión: **553 respuestas / 72 operaciones**, sin errores ni advertencias, incluido el caso de parámetros no admitidos. [Resultado final](qa/1.6d/openapi-final.json). Los cuerpos reales que contienen sesiones o tokens se mantienen privados; se publica únicamente el resultado saneado del validador.

## P. Visual

Computer Use falló dos veces al inicializar `@oai/sky`; el navegador integrado también terminó su proceso. Se utilizó **Chrome real 157.0.8103.79 mediante CDP**, con servidor QA 8085, perfil/cookies/archivos separados y única conexión registrada a fresapos_test. Los usuarios y datos empresariales mostrados son fixtures. El arnés QA no inició sesiones operativas ni modificó datos de fresapos; la actividad Web operativa detectada durante la regresión se describe en Q.

Se capturaron **124 imágenes únicas** en **1440×900, 1024×768, 768×1024 y 390×844**. El registro contiene 132 capturas: ocho nombres repetidos al recuperar una ejecución fueron reemplazados por la captura final del mismo escenario. No hubo desbordamiento horizontal, errores JavaScript de página ni botones nuevos menores de 44 px. Se verificaron POS pagado, vista previa y documento preparado, pagos simples/mixtos, descuentos, total cero, corrección confirmada, bloqueo de estados no elegibles, teclado, lectura sin permiso de impresión y cambio real de empleado mediante PIN. Los productos conservaron los nombres y precios históricos después de modificar Catalog en los fixtures.

El motor del navegador generó **12 PDF** y se renderizaron **17 páginas PNG**: once documentos continuos y el ticket largo también paginado en seis páginas. Ancho físico del PDF **80.095 mm** por cuantización de Chrome y contenido **73.997 mm**, nominales 80/74 mm. Sin recorte horizontal, controles impresos, referencias bancarias, identificador fiscal deshabilitado o precios del catálogo actualizado. Se comprobaron los 26 productos y la última nota del ticket largo. Se inspeccionaron visualmente los tickets y las seis páginas largas; la muestra continua larga se revisó también en cinco segmentos superpuestos a resolución legible, de cabecera a pie, además de su comprobación textual completa. Estos recortes privados son auxiliares de inspección, no páginas o capturas adicionales del registro público.

El botón explícito llamó la función nativa `window.print()` **una vez** y emitió **un evento beforeprint**. No se observó afterprint antes de cerrar el navegador; no se considera confirmación de impresión. Un intento inicial de exportar PDF después de abrir impresión nativa produjo «Page is already being printed» en el arnés QA; se recuperó una página aislada y se exportaron los PDF antes de activar la impresión nativa al final. No se modificó el flujo del producto por este incidente.

**Impresión validada desde navegador; impresora física no comprobada.** No se envió un trabajo a una impresora real ni se instalaron controladores. La evidencia SQL visual conserva ocho tablas financieras idénticas, 12 eventos de checkout, 13 solicitudes de reimpresión (incluida una repetición de QA), ninguna afirmación de éxito físico y atribución correcta al empleado actual tras el PIN. Las 28 invariantes financieras resultaron sin violaciones. El navegador y el servidor QA se cerraron y sus fixtures se retiraron antes de iniciar la regresión final.

Índice, capturas, mediciones, carreras SQL y muestra PDF: [docs/qa/1.6d](qa/1.6d/README.md).

## Q. Integridad

La comparación de solo lectura confirmó **28 tablas / 17 migraciones**, sin cambios en los valores previos de ninguna tabla ni en sus columnas. Los diez archivos protegidos coinciden con su SHA-256 inicial: `.env`, `.env.testing`, database/session/filesystems, composer.json/lock, phpunit.xml, phpstan.neon y seguridad Users. Permanecen intactos conexión Windows, administrador, roles, permisos y datos financieros operativos.

La primera comparación final detectó dos adiciones legítimas durante la ejecución: **un login Web a las 10:27:07 del 20 de noviembre** (16:27:07 UTC) y su sesión. El registro del servidor operativo muestra navegación por login, account, cash y admin. No fue una prueba de Tickets ni se restauraron o borraron esas filas. Se verificó el vínculo entre evento, actor y sesión sin publicar identificadores o secretos. Al separar las filas posteriores a la captura inicial (14:52:58 UTC), los hashes de las **66 auditorías y 13 sesiones originales coinciden exactamente**. Las otras 26 tablas coinciden íntegramente. La evidencia conserva tanto la diferencia de las dos tablas completas como la comprobación satisfactoria de todos los registros previos; no afirma que el sistema operativo haya permanecido sin actividad.

La sonda financiera operativa comprobó **64 columnas ULID y 28 invariantes**, todas sin violaciones. Las **16 comprobaciones HTTP** sobre 127.0.0.1:8080 pasaron, incluidas Web, Livewire, API, POS, Cash y las nuevas rutas protegidas de comprobantes. Tras una interrupción externa del proceso local se restableció artisan serve con la misma configuración; se deja disponible en el puerto 8080. Al terminar la regresión se comprobó **fresapos_test con cero tablas**; no fue necesaria limpieza adicional. Los fixtures se retiraron mediante el ciclo normal de las pruebas.

Evidencia saneada: [integridad operativa](qa/1.6d/operative-integrity.json). Los hashes privados de filas, credenciales y cuerpos autenticados no se publican.

## R. Pendientes

Pendientes externos: impresora física, selección de papel y márgenes de su controlador, Azure SQL/cPanel, ODBC, extensiones PHP, TLS/firewall, ionCube y Scheduler. No se comprobaron en esta entrega. Reportes generales PDF/Excel, ESC/POS, impresión silenciosa y dispositivos quedan fuera de alcance. Los PDF de QA son evidencia de impresión de tickets; no constituyen un módulo de reportes.

No se realizaron commits, pushes ni despliegues externos. No se avanza a 1.6e. Se acepta localmente la etapa por la regresión completa satisfactoria, calidad estática, contrato API, revisión visual, impresión del navegador e integridad operativa verificadas. Permanece pendiente la comprobación de una impresora física y los entornos externos indicados; no se declara éxito físico.
