# QA · Caja UI-07C

Las 24 páginas exportadas son fixtures ficticias: dos fases y doce escenarios. El exportador elimina las conexiones SQL, usa sesión array y no hidrata Livewire. Sustituye los hijos por las vistas renderizadas en memoria solamente donde las condiciones del padre dejaron sus marcadores. El script operativo se excluye del render aislado y se compara por separado contra el original.

Los valores asociados a `wire:model` se rellenan únicamente en el HTML de QA. Los indicadores `wire:loading`, `wire:loading.delay` y `wire:offline` se ocultan en reposo; los mensajes de red se muestran expresamente para inspección. No describen una sesión real ni el estado de la red operativa.

Desde PowerShell, en la raíz:

```powershell
.\scripts\local.cmd php docs/ui-ux/qa/ui-07c/export-views.php
.\scripts\local.cmd php -S 127.0.0.1:8099 docs/ui-ux/qa/ui-07c/router.php
```

[Abrir Caja de QA](http://127.0.0.1:8099/after-open.html), [arqueo ciego](http://127.0.0.1:8099/after-blind.html). La aplicación operativa sigue en el puerto 8080. El router limita las respuestas a los archivos enumerados y a GET; no contiene rutas de negocio.

`browser-check.cjs` usa el Playwright y Node locales ya disponibles para QA, sin instalar paquetes ni crear requisitos de hosting. La matriz abarca nueve estados, dos temas, siete resoluciones en Chrome y dos en Edge: 162 combinaciones. Incluye apertura, sesión abierta, arqueo, cierre pendiente, movimiento pendiente, ventas que bloquean el cierre, resumen con faltante, errores y solo lectura. Los escenarios adicionales cubren movimiento resuelto, caja no aprovisionada, historial vacío, red, textos largos, fuente al doble, preferencias automáticas y almacenamiento bloqueado.

[Resultado del navegador](../../evidencias/ui-07c/browser-results.json), [resumen](browser-summary.json), [matriz](responsive.csv), [calidad y JUnit](quality.json), [integridad](source-integrity.json), [base independiente después de pruebas](test-tables.json), [HTTP local](local-http.json). El manifiesto incluye las huellas de los archivos de esta entrega; excluye su propio archivo, el comprobante de cierre y el índice vivo de estados.

La regresión SQL utiliza nueve clases: CashTest, CashHttpTest, CashFinanceTest, CashFinanceHttpTest, CashFinanceLivewireTest, CashReconciliationTest, CashReconciliationHttpTest, CashReconciliationLivewireTest y CashPresentationHttpTest. Se ejecutan en una sola invocación, secuencialmente, usando las salvaguardas y el teardown existentes de `fresapos_test`.

Se conservan los logs de intentos: el primero de las pruebas nuevas falló por ausencia de Mockery y se reemplazó el helper por un doble local sin dependencias. En la primera matriz, la inspección manual detectó loading diferido visible en reposo en la fixture. Se conserva el intento y una captura; se corrigió únicamente la representación de QA y se repitió la matriz completa. `loading-check.cjs` comprueba los tres tipos de indicadores ocultos en reposo.

Al cerrar apareció staging concurrente de 608 archivos de etapas previas. Se guardó el [registro observado](concurrent-index.json) y se preservaron las entradas del índice; no se hizo staging ni commit desde esta tarea. La comprobación inicial de índice vacío falló por ese cambio concurrente y fue sustituida por una comparación del índice observado, sin retirarlo ni modificar los archivos preparados.

Los logs de PowerShell están en UTF-16LE; las fuentes, JSON y documentación, en UTF-8. Las mediciones LCP/CLS usan cinco recargas calientes por fase de una página estática local y no equivalen a métricas de producción o de Livewire autenticado.

Pendientes: navegador autenticado extremo a extremo, lector de pantalla, auditoría WCAG completa, Firefox/Safari, dispositivos táctiles y zoom nativo, axe/Lighthouse/INP y comprobación en Azure SQL/cPanel. No se repiten toda la suite global ni los tres grupos de concurrencia SQL; se ejecutan las suites y clases relacionadas indicadas en el informe.
