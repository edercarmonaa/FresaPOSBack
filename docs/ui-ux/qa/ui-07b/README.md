# QA de UI-07B

Fixtures Blade de la pantalla de Motivos, con datos ficticios y nombre que contiene etiquetas escapadas. El exportador anula todas las conexiones SQL y usa sesión array; no hidrata Livewire. Los campos se rellenan solo en el HTML exportado. Loading y offline se ocultan en reposo y se muestran explícitamente al inspeccionar mensajes. No representan conectividad real.

Desde PowerShell, en la raíz del repositorio:

```powershell
.\scripts\local.cmd php docs/ui-ux/qa/ui-07b/export-views.php
.\scripts\local.cmd php -S 127.0.0.1:8098 docs/ui-ux/qa/ui-07b/router.php
```

El segundo comando sirve únicamente los archivos enumerados por el router, por GET. [Abrir fixture](http://127.0.0.1:8098/after-create.html). La aplicación operativa sigue en el puerto 8080. No ejecutar acciones de negocio desde las fixtures.

Los scripts `browser-check.cjs` y `additional-checks.cjs` usan el Playwright ya disponible en `.tools/node_modules`; no instalan paquetes ni agregan requisitos de hosting. Ejecutarlos con el Node local de QA. `browser-check.cjs --submitted-only` revisa las 18 combinaciones del estado cuyo tono informativo se ajustó al final.

La matriz principal contiene 126 combinaciones: Chrome en siete resoluciones y Edge en 1366×768 y 390×844; siete estados y dos temas. Hay 17 comprobaciones explícitas, 22 pares de contraste y 18 capturas adicionales del estado enviado. [Matriz](responsive.csv), [resumen](browser-summary.json), [resultado principal](../../evidencias/ui-07b/browser-results.json), [revisión final de estado](../../evidencias/ui-07b/submitted-review-results.json), [comprobaciones adicionales](additional-results.json).

Las capturas principales del estado enviado preceden al ajuste final de tono: usar las capturas `review-*` para revisar su aspecto final. Los otros estados no cambiaron. Se conserva el [primer intento](browser-attempt-01.json) y una [captura del problema de representación offline](../../evidencias/ui-07b/fixture-offline-before-correction.png). Ese intento pasó comprobaciones automáticas, pero la inspección manual detectó que la fixture mostraba offline en reposo.

También se conservan `final-pint-attempt-01.log` y `final-attempt-01-results.json`: Pint señaló formato en los dos PHP nuevos de QA; se formatearon exclusivamente esos archivos y se repitió Pint global con resultado correcto. [Calidad](quality.json), [integridad](source-integrity.json), [base independiente tras pruebas](test-tables.json), [HTTP local](local-http.json).

Los logs PowerShell están en UTF-16LE. Los JSON, fuentes y documentación están en UTF-8. Las mediciones de rendimiento son de fixtures locales con caché caliente, cinco recargas por fase; no son métricas de usuarios reales ni una medición de Livewire autenticado.

Pendientes: navegador autenticado extremo a extremo, lector de pantalla y evaluación WCAG completa, Firefox/Safari, dispositivos táctiles y zoom nativo, axe/Lighthouse/INP, Azure SQL y hosting cPanel. No se repitió toda la suite global del proyecto; sí Feature, Architecture y las cuatro clases SQL de Motivos indicadas en el informe.
