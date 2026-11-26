# QA UI-07A · Catálogo

27/11/2026. HEAD `be38e26`, main. Inicio con UI-06 sin commit: se guardaron hashes de 3.436 archivos versionados y no versionados. Solo cambian seis vistas de Catálogo y el índice evolutivo. Los 174 archivos de pruebas existentes y diez protegidos permanecen intactos. [Integridad](source-integrity.json).

## Comandos y resultados

Desde la raíz, en PowerShell:

```powershell
.\scripts\local.cmd php -d memory_limit=1536M vendor/phpunit/phpunit/phpunit tests/Feature tests/Architecture --log-junit .tools/ui07a-baseline.xml
.\scripts\local.cmd php vendor/phpunit/phpunit/phpunit tests/Feature/CatalogPresentationTest.php
.\scripts\local.cmd php -d memory_limit=1536M vendor/phpunit/phpunit/phpunit tests/Feature tests/Architecture --log-junit .tools/ui07a-final.xml
.\scripts\local.cmd php -d memory_limit=1536M vendor/phpunit/phpunit/phpunit tests/Integration/CatalogAcceptanceTest.php tests/Integration/CatalogHttpTest.php tests/Integration/CatalogImagesTest.php tests/Integration/CatalogPresentationHttpTest.php --log-junit .tools/ui07a-integration-final.xml
.\scripts\local.cmd composer lint
.\scripts\local.cmd composer analyse
.\scripts\local.cmd composer validate --strict
.\scripts\local.cmd composer check-platform-reqs
```

| Ejecución | Resultado real |
|---|---|
| Línea base Feature + Architecture | 312 pruebas / 1.287 aserciones; 22,169 s; 90 MB |
| Nueva comparación de presentación | 7 / 62; 3,777 s; 48 MB |
| Final Feature + Architecture | 319 / 1.349; 29,266 s; 90 MB |
| Primera integración, tres clases previas | 48 / 839; un fallo por clase de desplazamiento retirada |
| Integración final, cuatro clases | 49 / 858; 150,645 s; 82 MB; sin fallos, errores u omitidas |

Pint, Larastan, Composer estricto y plataforma: código cero en línea base y final. [JUnit y códigos](quality.json). No hubo ejecución SQL de integración antes de modificar las vistas; no se inventa una línea base de esa suite. La comparación nueva cubre 60 pares de renderizados y dos límites de página. La nueva prueba HTTP autentica una sesión de prueba y confirma que cada recurso local se carga exactamente una vez en ambas páginas.

La regresión SQL cubre ciclo CRUD, validación, permisos y revocación, precisión de precios, categorías, papelera, imágenes, firmas/previews de subida y acciones Livewire. Se ejecuta con guardas contra `fresapos_test`. Después: cero tablas, [evidencia](test-db-after.json). No se ejecutaron pruebas financieras sobre fresapos. No se verificaron hashes de filas operativas.

## Navegador

```powershell
.\scripts\local.cmd php docs/ui-ux/qa/ui-07a/export-views.php
.\scripts\local.cmd php -S 127.0.0.1:8097 docs/ui-ux/qa/ui-07a/router.php
```

En otra consola, sin instalar herramientas:

```powershell
& 'C:/Users/Usuario/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/bin/node.exe' docs/ui-ux/qa/ui-07a/browser-check.cjs
& 'C:/Users/Usuario/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/bin/node.exe' docs/ui-ux/qa/ui-07a/browser-check.cjs --edit-only
& 'C:/Users/Usuario/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/bin/node.exe' docs/ui-ux/qa/ui-07a/additional-checks.cjs
```

El exportador elimina conexiones SQL y usa Gate/identidad en memoria. Los campos se rellenan con datos ficticios de bindings; no se ejecuta ni valida hidratación Livewire. Los loaders se ocultan en reposo solo en la fixture. El SVG marcado QA no se sube ni amplía formatos admitidos. Tokens reemplazados por `UI07A_STATIC_NO_SESSION`. Router solo GET y lista cerrada; navegador bloquea envíos y peticiones no autorizadas. No se pulsan acciones de negocio en estas fixtures.

Chrome 158.0.8127.37: siete referencias de 2560×1440 a 320×844, incluidos 1920×1080, 1366×768, 1024×768, 768×1024 y 390×844. Edge 158.0.4370.45: escritorio y 390×844. Dos temas para productos (lista, creación, edición y papelera) y categorías (edición y vacío): 108 capturas. Tras el último borde de error se repiten las dos vistas de edición: 36 capturas y 36 comprobaciones. La regla adicional solo afecta controles aria-invalid; los demás estados conservan sus estilos comprobados.

Resultado final: sin desbordamientos de página, IDs duplicados, controles sin nombre o imágenes rotas. Las tablas se desplazan localmente y mantienen columnas. Targets de 44 px, contando etiqueta asociada en checkbox de 22 px. Diecisiete comprobaciones principales y siete adicionales pasan: precisión del precio ficticio, categoría inactiva actual, formato del campo, MIME, confirmación de imagen, asociaciones de error, validación nativa, teclado, desplazamiento, densidad, tema, movimiento, vacíos, fuente raíz duplicada, storage bloqueado y restauración. Veintidós pares de contraste cumplen: texto mínimo 5,86:1, no textual 3,29:1.

155 PNG: 108 principales, 36 focalizadas, ocho antes, dos vacíos de productos y una ampliación de fuente. El «antes» usa las vistas y helpers capturados al inicio, dentro del layout compartido preservado. [Resumen](browser-summary.json), [matriz](responsive.csv), [resultado principal](../../evidencias/ui-07a/browser-results.json), [revisión de edición](../../evidencias/ui-07a/edit-review-results.json).

## Incidencias conservadas

Conversión inicial de etiquetas: se ajustó el procesamiento para no cortar expresiones Blade con flechas o comparaciones. Primera prueba nueva: siete errores de compilación por `@disabled` dentro de componentes. Se cambió a `:disabled` con idénticas condiciones. Un comando posterior tenía una ruta PHPUnit duplicada y no ejecutó pruebas. Dos comparaciones nuevas detectaron diferencias de espacios y serialización booleanas equivalentes; se normalizaron nodos significativos, tipo text implícito y booleanos, manteniendo presencia y valores funcionales. Se conservan los cuatro logs de intentos.

La primera integración detectó que una prueba previa exige `overflow-x-auto`. Se conservó esa clase en el componente; se repitieron todas las clases y se añadió una comprobación HTTP de recursos: 49 pasan. No se modificó la prueba existente. [Intento SQL](integration-attempt-01.xml).

Primera matriz visual: 108 casos sin fallos de geometría y 17 checks pasan, pero dos pares de contraste fallan durante interpolación del fondo al cambiar tema. [Resultado inicial](browser-attempt-01.json). Se eliminó la interpolación de fondo solo en Catálogo y se repitió toda la matriz, satisfactoria. Se mejoraron la legibilidad del estado y el tamaño del botón de imagen. En revisión final, el puente de estilos heredado tapaba el borde de error del componente; una regla local lo restablece y se ejecutan 36 casos focalizados y mediciones actualizadas. Sin cambios en UI-06.

## Rendimiento y límites

Chrome headless, 1366×768, escala 1, caché caliente, sin throttling, n=5 por versión de la lista ficticia. LCP antes mínimo/mediana/máximo 128/148/160 ms; después 128/144/176 ms; CLS 0. Rangos solapados: no se concluye mejora causal ni tiempo de consultas reales. CSS añadido: 4.723 bytes sin comprimir. JS compartido UI-06 reutilizado, sin cambios; sin paquetes o fuentes nuevos.

Pendientes: E2E autenticado en navegador, suite global de otras áreas, lectores de pantalla, Firefox/Safari, tacto real, zoom nativo, axe/Lighthouse/INP, Azure SQL/cPanel. Las operaciones HTTP/Livewire contra SQL real de pruebas no equivalen a E2E del navegador. [Sintaxis y HTTP](syntax-health.json): correctos, up/login 200 y rutas Catálogo anónimas 302.

El manifiesto excluye su propio archivo, `delivery-check.json` y `ESTADO.md`. La aprobación de UI-07A y el siguiente módulo quedan pendientes.
