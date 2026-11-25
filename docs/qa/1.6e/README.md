# Evidencias locales — Reportes 1.6e

23 de noviembre de 2026. Exclusivamente datos ficticios de fresapos_test. No son reportes de un negocio real. El informe de aceptación y las limitaciones están en [entrega 1.6e](../../entrega-1.6e.md).

## Navegador

Chrome real, instancia QA aislada 8085, conexiones SQL limitadas a fresapos_test, sesiones/caché/archivos separados. Las integraciones y la revisión visual no se ejecutaron simultáneamente sobre esa base. El navegador integrado falló al arrancar; se utilizó Chrome/CDP y se cerraron los procesos propios al finalizar.

[102 capturas y comprobaciones de anchura/JavaScript](visual.json). La matriz final contiene 72 imágenes: nueve reportes × encabezado/resultados × cuatro resoluciones. Otras 30 documentan vacío, error, filtro, paginación, rol denegado, cierre, dos turnos, navegación y teclado. No hay desbordamiento horizontal o errores JavaScript en esas observaciones. Los controles Reports verificados cumplen 44 px.

| Reporte | Escritorio | Móvil |
| --- | --- | --- |
| Resumen | [1440](final-01-summary-header-1440.png) | [390](final-01-summary-header-390.png) |
| Ventas | [1440](final-02-sales-results-1440.png) | [390](final-02-sales-results-390.png) |
| Productos | [1440](final-03-products-results-1440.png) | [390](final-03-products-results-390.png) |
| Pagos | [1440](final-04-payments-results-1440.png) | [390](final-04-payments-results-390.png) |
| Movimientos | [1440](final-05-cash-movements-results-1440.png) | [390](final-05-cash-movements-results-390.png) |
| Sesiones | [1440](final-06-cash-sessions-results-1440.png) | [390](final-06-cash-sessions-results-390.png) |
| Arqueos | [1440](final-07-cash-reconciliations-results-1440.png) | [390](final-07-cash-reconciliations-results-390.png) |
| Descuentos | [1440](final-08-discounts-results-1440.png) | [390](final-08-discounts-results-390.png) |
| Correcciones | [1440](final-09-corrections-results-1440.png) | [390](final-09-corrections-results-390.png) |

También existen los archivos equivalentes de 1024×768 y 768×1024; la lista completa está en visual.json. [Acceso denegado](14-forbidden-390.png), [paginación](10-paging-1440.png), [vacío](11-empty-390.png), [cierre](15-closed-390.png), [dos turnos](16-two-sessions-1440.png), [navegación](18-navigation-1440.png) y [teclado](19-keyboard-1440.png).

## Archivos reales

[Resultado de comprobación de archivos](files.json): diez PDF, quince páginas y diez XLSX. Nueve pares corresponden a los reportes iniciales; el par adicional al cierre histórico. Las distintas fechas de generación corresponden a acciones controladas del fixture; los archivos iniciales no se recalculan después de cerrar el turno.

| Muestra | PDF | XLSX |
| --- | --- | --- |
| Resumen | [Documento](report-summary-2026-11-23-2026-11-23.pdf) | [Libro](report-summary-2026-11-23-2026-11-23.xlsx) |
| Ventas | [Documento](report-sales-2026-11-23-2026-11-23.pdf) | [Libro](report-sales-2026-11-23-2026-11-23.xlsx) |
| Productos largos | [Cinco páginas](report-products-2026-11-23-2026-11-23.pdf) | [Libro](report-products-2026-11-23-2026-11-23.xlsx) |
| Pagos | [Documento](report-payments-2026-11-23-2026-11-23.pdf) | [Libro](report-payments-2026-11-23-2026-11-23.xlsx) |
| Movimientos | [Documento](report-cash-movements-2026-11-23-2026-11-23.pdf) | [Libro](report-cash-movements-2026-11-23-2026-11-23.xlsx) |
| Sesión abierta | [Documento](report-cash-sessions-2026-11-23-2026-11-23.pdf) | [Libro](report-cash-sessions-2026-11-23-2026-11-23.xlsx) |
| Arqueos vacío | [Documento](report-cash-reconciliations-2026-11-23-2026-11-23.pdf) | [Libro](report-cash-reconciliations-2026-11-23-2026-11-23.xlsx) |
| Descuentos | [Documento](report-discounts-2026-11-23-2026-11-23.pdf) | [Libro](report-discounts-2026-11-23-2026-11-23.xlsx) |
| Correcciones | [Documento](report-corrections-2026-11-23-2026-11-23.pdf) | [Libro](report-corrections-2026-11-23-2026-11-23.xlsx) |
| Cierre histórico | [Documento](report-closed-cash-2026-11-23.pdf) | [Libro](report-closed-cash-2026-11-23.xlsx) |

PDF renderizados con Poppler y verificados con Pypdf/Pdfplumber: márgenes, texto, encabezados/páginas y ausencia de referencia privada. XLSX reabiertos con PhpSpreadsheet/Openpyxl: fechas numéricas, montos, tipos de texto, filtros, paneles congelados y cero fórmulas/macros. Todas las hojas se renderizaron desde el archivo descargado; imágenes junto a los originales con sufijo -xlsx.png. La generación productiva usa PHP; los renderizadores de QA no forman parte del hosting.

## Contratos y concurrencia

[OpenAPI previo al cierre de regresión](openapi-pre-final.json): 74 operaciones, 609 respuestas reales, cero errores/advertencias. Los cuerpos con autenticación permanecen privados. Los éxitos binarios se validaron como archivos reales.

[Carrera SQL](concurrency.json): SPID distintos, bloqueo LCK_M_X; el reporte se canceló con 503 sin datos parciales para permitir la corrección y el reintento leyó la nueva versión completa. No se afirma ausencia de contención ni activación de SNAPSHOT.

[Regresión completa y repetición final](quality.json), [benchmark de 1001 ventas](performance.json), [OpenAPI final](openapi-final.json), [integridad y diferencias previas](integrity.json), [archivos protegidos/pruebas](protected-and-tests.json), [salud local](health.json) y [base de pruebas vacía](test-database-empty.json). Impresora física, Excel de escritorio, Azure SQL, cPanel/ODBC/ionCube y Scheduler del sistema no verificados.

Las muestras finales se regeneraron por HTTP API real después de compactar los indicadores de Excel. [Dieciocho descargas](http-downloads.json) y [dos de cierre](http-closed-downloads.json). [Fechas UTC, macros y altura congelada](files-additional.json). El render usa el rango explícito de cada hoja. Las capturas Web pertenecen al escenario independiente de Chrome; no se atribuyen sus identificadores de fixture a otro escenario.
