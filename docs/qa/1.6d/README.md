# Evidencias locales de tickets de 80 mm — 1.6d

20 de noviembre de 2026. Informe principal: [entrega 1.6d](../../entrega-1.6d.md). Estas evidencias pertenecen a datos ficticios de `fresapos_test`; los nombres, direcciones y usuarios mostrados no configuran el establecimiento operativo.

## Navegador y resoluciones

Chrome 157.0.8103.79 real mediante CDP. Computer Use y el navegador integrado fallaron al inicializar; se utilizó un perfil aislado, servidor QA 8085 y exclusivamente SQL Server de pruebas. El servidor y el navegador QA fueron cerrados al finalizar. La app operativa sigue disponible en 127.0.0.1:8080.

[Registro visual](visual-report.json): **124 capturas únicas** en 1440×900, 1024×768, 768×1024 y 390×844. Se produjeron 132 registros de captura; ocho nombres se repitieron durante la recuperación de una ejecución y el índice conserva la versión final. Cero desbordamientos horizontales, errores JavaScript de página o botones nuevos menores de 44 px.

Las capturas incluyen POS pagado, vista previa, copia preparada, efectivo, pagos mixtos, tarjeta, transferencia, otro método, descuentos fijo/porcentaje, dos variantes de total cero, venta corregida y ticket largo de 26 productos. También se verificaron estados bloqueados, navegación por teclado, usuario con lectura y sin impresión, y cambio de empleado mediante PIN.

| Ejemplo | Escritorio 1440×900 | Móvil 390×844 |
| --- | --- | --- |
| Acción de ticket en POS | [Ver](01-pos-ticket-action-1440.png) | [Ver](01-pos-ticket-action-390.png) |
| Vista previa | [Ver](02-cash-preview-1440.png) | [Ver](02-cash-preview-390.png) |
| Copia preparada | [Ver](02-cash-prepared-1440.png) | [Ver](02-cash-prepared-390.png) |
| Pagos mixtos | [Ver](03-mixed-prepared-1440.png) | [Ver](03-mixed-prepared-390.png) |

## Impresión desde navegador

[Mediciones PDF](print-report.json): **12 documentos / 17 páginas renderizadas**. Once salidas continuas y el ticket largo también paginado en seis páginas. Las imágenes de las páginas están en [rendered](rendered/). Ancho del papel PDF 80.095 mm y del contenido 73.997 mm por cuantización de Chrome, nominales 80/74 mm; no hubo recorte horizontal ni controles impresos. Se comprobaron los 26 productos y la última nota del ticket largo.

Muestra descargable: [ticket largo de 80 mm](ticket-largo-80mm.pdf). Los demás PDF son intermedios privados de QA; sus páginas renderizadas y sus mediciones están en este directorio.

Inspección directa del PDF renderizado: [efectivo](rendered/print-cash-80mm.png), [pagos mixtos](rendered/print-mixed-80mm.png), [corrección](rendered/print-corrected-80mm.png), [total cero](rendered/print-zero_fixed-80mm.png) y [ticket largo continuo](rendered/print-long-80mm.png).

[Acción nativa de impresión](native-print-report.json): el botón ejecutó `window.print()` una vez y se observó un evento `beforeprint`. No se observó `afterprint` antes de cerrar el navegador. Un intento inicial de exportar PDF con la impresión nativa abierta devolvió «Page is already being printed» en el arnés; se recuperó la página QA y se generaron los PDF antes de activar la impresión nativa al final.

**Impresión validada desde navegador; impresora física no comprobada.** No se envió un trabajo a un dispositivo físico ni se instalaron controladores. El ancho real, escala y márgenes deben verificarse con el papel de 80 mm del dispositivo que se utilice.

## Evidencia SQL, seguridad e integridad

[SQL de la revisión visual](sql-evidence.json): ocho tablas financieras conservadas, 14 ventas de fixture, 12 pagadas, 12 pagos, 12 eventos de checkout y 13 solicitudes auditadas de reimpresión, incluida una recuperación del arnés. Cero afirmaciones de éxito físico. La preparación posterior al PIN se atribuyó al empleado actual. Las 28 invariantes financieras pasaron.

Carreras reales con sesiones SQL independientes y espera de lock comprobada:

- [Lectura antes de reapertura](receipts-race-read_before_reopen.json).
- [Reapertura antes de lectura](receipts-race-reopen_before_read.json).
- [Confirmación antes de lectura](receipts-race-confirm_before_read.json).

Cada carrera conserva los importes, pagos y folio coherentes con una sola versión confirmada.

Verificaciones operativas de solo lectura:

- [28 tablas, 17 migraciones y diez archivos protegidos](operative-integrity.json).
- [28 invariantes y 64 columnas ULID](operative-financial-invariants.json).
- [16 comprobaciones HTTP de la app local](http-health.json).

Durante la regresión hubo un login Web operativo a las 10:27:07: añadió un evento y una sesión, conservados. La evidencia distingue esas dos adiciones de los registros previos: las 66 auditorías y 13 sesiones originales mantienen sus hashes, las otras 26 tablas coinciden íntegramente y no cambió ninguna columna. No se borró ni revirtió actividad del sistema para forzar una comparación idéntica.

## Calidad y privacidad

[Inventario de pruebas](receipts-inventory.json) y [Pint, Larastan y Composer](receipts-final-quality.json). [PHPUnit final](tests-summary.json): **821 pruebas / 9736 aserciones**, exit 0. [OpenAPI final](openapi-final.json): **553 respuestas reales / 72 operaciones**, sin errores ni advertencias. fresapos_test terminó con cero tablas.

No se publican archivos .env, contraseñas, intenciones cifradas, cookies, tokens, cuerpos autenticados sin sanear, copias de bases ni hashes privados de filas operativas. Las capturas utilizan únicamente sesiones y datos de fixture. Los resultados intermedios fallidos y sus correcciones se describen en la sección N del informe; no se presentan como aprobados.
