# QA · Reportes UI-07D

38 páginas estáticas: dos fases y diecinueve escenarios. El exportador anula las conexiones SQL, usa sesión array y un usuario ficticio en memoria. No inicia una sesión operativa ni solicita reportes al servidor. CSRF se reemplaza por un marcador de QA en el HTML exportado.

Desde PowerShell en la raíz:

```powershell
.\scripts\local.cmd php docs/ui-ux/qa/ui-07d/export-views.php
.\scripts\local.cmd php -S 127.0.0.1:8100 docs/ui-ux/qa/ui-07d/router.php
```

[Ventas de QA](http://127.0.0.1:8100/after-sales.html), [Resumen](http://127.0.0.1:8100/after-summary.html), [error de validación](http://127.0.0.1:8100/after-error-validation.html). El router solo sirve archivos enumerados por GET. Las fixtures no son rutas de Laravel ni permiten operar. La aplicación real sigue en 8080.

`browser-check.cjs` utiliza Node y Playwright ya disponibles en `.tools`, sin instalar dependencias. La matriz abarca nueve variantes, dos temas, siete resoluciones en Chrome y dos en Edge: 162 combinaciones. Los escenarios adicionales cubren vacío, falta de permiso de exportación, límites exactos, última página, rango predefinido y errores.

La prueba de envío de filtros dispara el evento original con preventDefault y captura FormData; no envía una consulta. Los botones POST de exportación se inspeccionan sin pulsarlos. El navegador bloquea solicitudes distintas de GET o dirigidas fuera del servidor de QA y del CDN Tailwind ya existente. Las exportaciones reales de regresión se ejecutan exclusivamente por PHPUnit en `fresapos_test`.

[Resultado de navegador](../../evidencias/ui-07d/browser-results.json), [resumen](browser-summary.json), [matriz](responsive.csv), [calidad](quality.json), [integridad](source-integrity.json), [base de pruebas tras ejecución](test-tables.json), [HTTP local](local-http.json). Los logs PowerShell están en UTF-16LE; fuentes y JSON, en UTF-8.

La inspección manual inicial mostró una opción aislada en la navegación de pantalla amplia. Se ajustó su distribución y se repitió la matriz completa con la hoja CSS final. Se conserva el primer resultado y una captura intermedia. Las mediciones LCP/CLS usan cinco recargas calientes por fase, de una fixture local; no representan producción ni una mejora causal demostrada.

La integración seleccionada ejecuta OperationalReportsTest, ReportsHttpTest, ReportExportsTest y ReportAdministrationPresentationHttpTest de forma secuencial. No se modifican exportadores, consultas ni permisos. ReportsPerformanceTest y ReportsConcurrencyTest no se repiten en esta subetapa visual.

Al inicio el repositorio estaba limpio en un nuevo merge. Los manifiestos de UI-06 a UI-07C coinciden íntegramente; UI-05 ya registra una diferencia en el layout, modificada posteriormente por UI-06. No se reescriben evidencias históricas. El índice vivo de avance se actualiza con las aceptaciones registradas porque había vuelto a estados anteriores.

Pendientes: revisión visual autenticada extremo a extremo, lector de pantalla, auditoría WCAG integral, Firefox/Safari, dispositivos táctiles, zoom nativo, axe/Lighthouse/INP, Azure SQL y cPanel. Tampoco se repite toda la suite global; se indican las suites y clases realmente ejecutadas en el informe.
