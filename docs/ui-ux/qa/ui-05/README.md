# QA UI-05 · Indicadores y Reportes

Inicio limpio en `main`, commit `ee90df7`. Comparación de 3.130 archivos inicialmente versionados: cuatro modificaciones autorizadas, diez archivos protegidos intactos y 168 archivos existentes bajo pruebas sin cambios. [Integridad](source-integrity.json).

## Regresión ejecutada

Desde la raíz en PowerShell:

```powershell
.\scripts\local.cmd php -d memory_limit=1536M vendor/phpunit/phpunit/phpunit tests/Feature tests/Architecture --log-junit .tools/ui05-baseline.xml
.\scripts\local.cmd php -d memory_limit=1536M vendor/phpunit/phpunit/phpunit tests/Feature tests/Architecture --log-junit .tools/ui05-final.xml
.\scripts\local.cmd php -d memory_limit=1536M vendor/phpunit/phpunit/phpunit tests/Integration/ReportsHttpTest.php --log-junit .tools/ui05-integration.xml
.\scripts\local.cmd composer lint
.\scripts\local.cmd composer analyse
.\scripts\local.cmd composer validate --strict
.\scripts\local.cmd composer check-platform-reqs
```

| Comprobación | Inicial | Final |
|---|---|---|
| Feature + Architecture | 285 pruebas / 1.167 aserciones; 22,478 s; 88 MB | 305 / 1.227; 48,906 s; 90 MB |
| Pint | Código 0 | Código 0 |
| Larastan | Código 0 | Código 0 |
| Composer estricto / plataforma | Código 0 / 0 | Código 0 / 0 |
| ReportsHttpTest, SQL independiente | No ejecutado antes de cambios | 12 pruebas / 359 aserciones; 73,130 s; 86 MB; código 0 |

Cero fallos, errores u omitidas en esas ejecuciones. No se atribuye a la línea base una ejecución de integración que no se hizo. La nueva clase de presentación pasa 20 pruebas / 60 aserciones y está incluida en el total final. Compara nueve reportes, dos permisos y tres cantidades de filas: 54 comparativas renderizadas, además de errores escapados y JavaScript idéntico.

La regresión seleccionada cubre el área afectada. No se repitió la suite global de POS, finanzas y todas las integraciones; el resultado histórico de UI-03 no se presenta como actual. No se quitaron ni omitieron casos de las suites seleccionadas. [JUnit y códigos](quality.json).

## QA visual reproducible

```powershell
.\scripts\local.cmd php docs/ui-ux/qa/ui-05/export-views.php
.\scripts\local.cmd php -S 127.0.0.1:8095 docs/ui-ux/qa/ui-05/router.php
```

En otra consola, usando el runtime de QA ya disponible:

```powershell
& 'C:/Users/Usuario/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/bin/node.exe' docs/ui-ux/qa/ui-05/browser-check.cjs
& 'C:/Users/Usuario/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/bin/node.exe' docs/ui-ux/qa/ui-05/additional-checks.cjs
```

El exportador usa Gate e identidad en memoria, sesión array y elimina las conexiones SQL del proceso. Sustituye tokens por `UI05_STATIC_NO_SESSION`. El router admite solo GET de recursos enumerados y no inicia Laravel. El navegador bloquea cualquier envío en la matriz principal. La prueba del manejador submit usa `preventDefault`; verifica el comportamiento local original sin navegación ni petición.

Se renderizan Resumen, Ventas, Métodos de pago, Sesiones Cash, conjunto vacío y error. Chrome 158.0.8127.37: 2560×1440, 1920×1080, 1366×768, 1024×768, 768×1024, 390×844 y 320×844. Edge 158.0.4370.33: 1366×768 y 390×844. Dos temas por vista: 108 capturas, sin desbordamiento de página, controles sin nombre, IDs duplicados, controles inferiores a 44 px o errores de consola. Ocho comparativas anteriores y una captura con tamaño raíz duplicado: 117 PNG.

Doce comprobaciones pasan: rangos de fecha, filtros vacíos, teclado, foco, precisión recibida, tema automático, paleta oscura de Reportes, densidad, movimiento reducido, reflujo con fuente raíz duplicada y almacenamiento bloqueado. Cuatro adicionales comprueban estado vacío y regiones enfocables en ambos temas. Se midieron 26 pares: mínimo de texto 6,29:1 (umbral 4,5) y no textual 3,29:1 (umbral 3). [Resumen](browser-summary.json), [resultados de navegador](../../evidencias/ui-05/browser-results.json), [resultados adicionales](additional-results.json).

Inspección visual de capturas: Resumen claro de escritorio, Ventas claro móvil, Resumen oscuro móvil y error oscuro a 320 px. Se revisaron jerarquía, contraste, acciones, tabla por tarjetas y gráfica. Las fixtures son ilustraciones de presentación; no son conciliaciones financieras ni sesiones reales.

## Rendimiento

Chrome headless, 1366×768, escala 1, sin throttling, caché caliente; cinco recargas por versión de la fixture de Resumen. LCP antes: mínimo/mediana/máximo 280/372/400 ms. Después: 288/316/412 ms. CLS 0. Los intervalos se solapan y la muestra no permite concluir una mejora causal. No son mediciones de producción ni de INP. CSS nuevo de 7.178 bytes sin comprimir; se retira el CSS inline anterior de la vista. No se añaden JavaScript, paquetes o fuentes.

## Integridad y pendientes

Las dos vistas anteriores, los archivos finales de producto y las nuevas pruebas se conservan en snapshots; el manifiesto registra sus hashes. La comparación fuente confirma igualdad de `@can`, `@disabled`, `@selected`, CSRF, rutas y scripts. El layout cambia exclusivamente la exclusión del límite claro de Reportes. PDFs, XLSX, conexión SQL, backend, configuración y dependencias intactos.

Tras integración, `TestDatabaseGuard::assertConnected` confirmó `fresapos_test` sin tablas: [evidencia](test-db-after.json). No se ejecutaron pruebas financieras sobre `fresapos`; no se verificaron hashes de sus filas. [Sintaxis PHP/Node, diff y HTTP](syntax-health.json): correctos; `/up` y `/login` 200; `/reports` sin autenticar 302.

Sin fallos de las pruebas ejecutadas ni incidentes de permisos en esta etapa. Pendientes: E2E autenticado de navegador con datos seguros, suite global repetida, lectores de pantalla, Firefox/Safari, dispositivos táctiles, zoom nativo, axe/Lighthouse/INP y validación Azure SQL/cPanel. La duplicación de fuente raíz no equivale a zoom nativo ni certifica WCAG.
