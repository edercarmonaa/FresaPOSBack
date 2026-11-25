# UI-00 — Línea base funcional y visual

Ejecución propia del **26/11/2026**, HEAD `1c750796f7d646e5d8c52cc49cb2890ee14b13da`. Sin modificaciones de código. Los resultados de 1.7a (906/11653, salida 0) son únicamente referencia histórica.

## PHPUnit

**906 pruebas / 11653 aserciones**, 35:27.808, salida 0; 0 fallos, 0 errores y 0 omitidas.

```powershell
.\scripts\local.cmd composer test -- --log-junit .tools/ui00-baseline-junit.xml
```

Composer dispone de COMPOSER_PROCESS_TIMEOUT=3600 exclusivamente en el proceso de comprobación. No se modifica composer.json, SQL, tiempos de las pruebas ni configuración global. PHPUnit fuerza testing/sqlsrv_testing; TestDatabaseGuard verifica nombre terminado en _test, distinto de fresapos, y SELECT DB_NAME() antes del DDL. La suite elimina únicamente sus fixtures. Un runner SQL; las capturas públicas no usan la base de integración ni credenciales. Los intentos de arranque de herramientas no se cuentan como ejecuciones PHPUnit.

## Calidad

Todos los comandos se ejecutaron con el lanzador `.\scripts\local.cmd`, sin instalar ni actualizar dependencias.

| Comando | Salida real |
| --- | --- |
| `composer lint` | 0 |
| `composer analyse (nivel 5)` | 0 |
| `composer validate --strict` | 0 |
| `composer check-platform-reqs` | 0 |

Larastan conserva su alcance configurado y nivel 5; no evalúa accesibilidad ni ejecución de JavaScript. Pint no se ejecutó en modo de escritura. No se cambió, omitió o debilitó ninguna prueba existente. [Resultados de calidad](qa/ui-00/quality.json).

## Navegador y capturas

Chrome 158.0.8127.37 y Edge 158.0.4370.33, headless, contextos nuevos, DPR 1, HTTP localhost sin throttling. **18 observaciones de página y 20 PNG**: inicio/login en siete tamaños Chrome y dos Edge; captura adicional de preferencia oscura y de foco. Se inspeccionaron visualmente representantes de login escritorio/móvil, inicio amplio y Edge móvil; todas las observaciones tienen geometría y estado de recursos registrados.

Resultados de esas páginas: 0 con desbordamiento horizontal, 0 excepciones de página, 0 mensajes console.error y 0 requests fallidas. Advertencias informativas del CDN no equivalen a errores de aplicación. Todos los formularios quedaron sin enviar; se bloquearon métodos distintos de GET. No se escribió usuario, contraseña o PIN. No se capturó información de sesión o negocio.

Chrome: 2560×1440, 1920×1080, 1366×768, 1024×768, 768×1024, 390×844 y 320×844. Edge: 1366×768 y 390×844. No se declara compatibilidad total ni prueba de pantallas protegidas. Firefox/WebKit no disponibles; Safari real no probado. Zoom, lector de pantalla, dispositivos físicos y todos los estados protegidos: pendientes.

## Accesibilidad acotada

Login: etiquetas asociadas, campos con autocomplete, CSRF presente (solo se registra su presencia), orden de foco usuario → contraseña → Entrar y outline nativo auto de 1 px. Contraste sobre fondo sólido: etiquetas 17.04:1, botón 17.83:1. Campos 42 px y botón 40 px: inferiores al objetivo táctil propio de 44 px; no se clasifican automáticamente como fallo de WCAG AA. Se observaron lang=es, un main y un h1. No se ejecutó axe, que no estaba instalado. No se certifica WCAG.

La preferencia oscura no altera el fondo de login y no hay selector de temas. No existen todavía temas claro/oscuro/automático configurables, por lo que no puede afirmarse que los tres modos estén implementados o validados. Reduced motion no provoca cambio visual identificable en estas páginas estáticas; estados animados protegidos no probados.

## Rendimiento inicial de laboratorio

Cinco recargas warm de login, Chrome a 1366×768, misma sesión de navegador, sin CPU/red simulada y con PHPUnit activo en el host. Valores observados hasta cada captura; no RUM, p75 de usuarios ni certificación Core Web Vitals. No se midió INP real ni una aproximación de interacción equivalente. No se ejecutó Lighthouse porque no está disponible y no se autorizaron instalaciones. [Metodología de métricas de campo](https://web.dev/articles/defining-core-web-vitals-thresholds).

| Métrica | N | Mínimo | Mediana | Máximo |
| --- | --- | --- | --- | --- |
| LCP observado, ms | 5 | 584.000 | 672.000 | 904.000 |
| CLS observado | 5 | 0.0000 | 0.0000 | 0.0000 |
| TTFB, ms | 5 | 454.400 | 532.300 | 779.800 |

No se extrapola a POS, equipos lentos, caché fría, usuarios reales o producción. Antes de comparar una etapa futura, repetir con condiciones equivalentes y sin carga concurrente, registrando explícitamente la diferencia respecto a esta muestra. Objetivos del encargo (LCP≤2.5 s, INP≤200 ms, CLS≤0.1, Lighthouse 90/95) siguen siendo objetivos; esta muestra no demuestra cumplimiento integral.

## Incidencias y verificaciones pendientes

CUA falló dos veces al inicializarse; la alternativa nativa también falló por sandbox/helper. No hubo interacción de escritorio. Playwright estaba instalado pero sus ejecutables bundled no; se usaron Chrome/Edge del sistema, sin descargas. Una sonda adicional de accesibilidad agotó 30 s esperando load. El segundo intento explícito esperó DOM y estilos con el mismo límite y pasó; el primero se conserva, sin atribuir su demora a red, SQL o CPU. [Registro de incidencias](qa/ui-00/tooling-incidents.json).

No se ejecutaron flujos autenticados, cobros, altas, exportaciones ni cambios de contraseña/PIN desde el navegador. La regresión PHPUnit sí ejecuta sus flujos reales en fresapos_test; eso no sustituye E2E visual ni teclado/AT. No se volvió a ejecutar el validador formal OpenAPI en UI-00; el contrato y rutas quedan sin cambios por SHA y la suite conserva sus pruebas API existentes. No se verificaron datos operativos mediante hashes de filas: la auditoría no emite DML operativo y prueba preservación de fuentes/configuración, pero no presenta una comparación de registros que no se realizó.

## Evidencia

[Inventario](02-inventario-pantallas.md), [capturas](evidencias/ui-00/README.md), [mediciones crudas](evidencias/ui-00/browser-baseline.json), [accesibilidad de login](evidencias/ui-00/accessibility-login.json), [resumen de rendimiento](qa/ui-00/performance-summary.json), [integridad](qa/ui-00/repository-integrity.json). JUnit y log completos permanecen privados en `.tools/ui00-baseline-*`; el resumen documental registra sus SHA-256.

## Comprobaciones al cierre

/up: 200, /api/v1/ping: 200. Base de pruebas validada fresapos_test: 0 tablas de usuario y 0 sesiones de usuario ajenas a la sonda. Solo SELECT; ninguna limpieza adicional. [Salud](qa/ui-00/health.json) y [base de pruebas](qa/ui-00/testing-database.json).
