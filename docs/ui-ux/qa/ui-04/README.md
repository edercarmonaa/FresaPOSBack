# QA UI-04 · Alcance y reproducción

Inicio limpio en `main`, commit `ced8d60`. [Integridad completa](source-integrity.json): 2.957 archivos inicialmente versionados, ocho modificaciones autorizadas, diez protegidos y 160 archivos de pruebas existentes intactos. Sin commits, staging ni pushes de esta entrega.

## Comandos ejecutados

Desde la raíz, en PowerShell:

```powershell
.\scripts\local.cmd php -d memory_limit=1536M vendor/phpunit/phpunit/phpunit tests/Feature --log-junit .tools/ui04-baseline.xml
.\scripts\local.cmd php -d memory_limit=1536M vendor/phpunit/phpunit/phpunit tests/Feature tests/Architecture --log-junit .tools/ui04-final.xml
.\scripts\local.cmd php -d memory_limit=1536M vendor/phpunit/phpunit/phpunit tests/Integration/AuthenticationTest.php tests/Integration/AdminBootstrapAuthenticationTest.php tests/Integration/AdvancedSecurityTest.php --log-junit .tools/ui04-auth-integration.xml
.\scripts\local.cmd composer lint
.\scripts\local.cmd composer analyse
.\scripts\local.cmd composer validate --strict
.\scripts\local.cmd composer check-platform-reqs
```

Resultados en [quality.json](quality.json), JUnit y logs de esta carpeta. Antes: Feature 277 pruebas / 1.073 aserciones, 18,241 s, 84 MB. Después: Feature + Architecture 285 / 1.167, 45,818 s, 88 MB. Dentro de ese total, Feature añade cuatro pruebas y 25 aserciones: 281 / 1.098; Architecture aporta 4 / 69. Regresión Auth SQL independiente: 48 / 450, 115,563 s, 76 MB. Cero errores, fallos u omitidas en esas ejecuciones. Una repetición posterior de la nueva clase con comparación de enlaces pasa 4 / 25. Pint, Larastan, Composer estricto y plataforma: código cero antes y después.

La estrategia de esta entrega ejecuta Feature, Architecture y las tres clases de integración directamente afectadas por Auth. No se repitió la suite global de finanzas, POS, reportes y demás integración; el resultado global histórico de UI-03 no se presenta como validación actual. No se han omitido ni alterado casos dentro de las suites seleccionadas.

## Navegador y seguridad de QA

`export-views.php` renderiza las vistas y sus componentes reales con identidad y Gate en memoria. Elimina las conexiones SQL de la configuración del proceso, usa sesión array y reemplaza los tokens por `UI04_STATIC_NO_SESSION`. Exporta ocho comparativas y dos estados ficticios. El router admite únicamente GET y una lista cerrada de recursos; no inicia Laravel.

```powershell
.\scripts\local.cmd php docs/ui-ux/qa/ui-04/export-views.php
.\scripts\local.cmd php -S 127.0.0.1:8094 docs/ui-ux/qa/ui-04/router.php
```

En otra consola, con el Node y Playwright de QA ya disponibles, sin instalar:

```powershell
& 'C:/Users/Usuario/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/bin/node.exe' docs/ui-ux/qa/ui-04/browser-check.cjs
& 'C:/Users/Usuario/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/bin/node.exe' docs/ui-ux/qa/ui-04/browser-check.cjs --checks-only
& 'C:/Users/Usuario/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/bin/node.exe' docs/ui-ux/qa/ui-04/additional-checks.cjs
```

La ejecución completa observada produjo 90 capturas: `/login` público real y cuatro fixtures, en claro/oscuro. Chrome 158.0.8127.37: 2560×1440, 1920×1080, 1366×768, 1024×768, 768×1024, 390×844 y 320×844. Edge 158.0.4370.33: 1366×768 y 390×844. Las 90 pasan geometría, nombres, IDs, fuente y controles de 44 px. Se añaden ocho capturas anteriores y cinco de estados/ampliación: 103 PNG.

El primer recorrido pasó nueve de diez interacciones y falló por un valor esperado incorrecto en QA: `reduce` frente al `reduced` existente. Se conservaron [resultado original](browser-attempt-01.json) y [log](browser-attempt-01.log). Se corrigió la expectativa, esperando el cambio asíncrono del media query, y se ejecutaron de nuevo solo las interacciones: diez de diez, sin errores de consola. No cambió el código de preferencias del producto. El archivo de resultados original en evidencias conserva el fallo; el [resumen](browser-summary.json) y [resultado corregido](../../evidencias/ui-04/browser-checks-final.json) distinguen ambas ejecuciones.

La primera medición adicional seleccionaba también el input CSRF oculto; el selector se corrigió a campos visibles dentro de main. Se conserva el log inicial. La medición final comprueba 30 pares: mínimo de texto 5,86:1 (umbral 4,5) y de borde 3,29:1 (umbral 3). Once comprobaciones adicionales pasan, incluida la duplicación del tamaño raíz a 320 px; no equivale a zoom nativo ni a certificación de accesibilidad.

Inspección visual de capturas reales: login claro de escritorio; cuenta oscura móvil; contraseña clara y error oscuro móvil. Se verificó jerarquía, legibilidad, envoltura de etiquetas, conservación de acciones y desplazamiento de tabla.

## Rendimiento

Chrome headless, escala 1, 1366×768, sin throttling, caché caliente, cinco recargas por versión sobre la fixture de login. Primera medición: LCP mediana antes 180 ms / después 176 ms. Repetición: antes 156 ms / después 192 ms. CLS 0 en ambas. La variación impide concluir una mejora causal; no es una medición de producción ni de INP. CSS añadido: 3.889 bytes sin comprimir; ningún JavaScript nuevo. La fuente y la dependencia CDN preexistente no cambiaron.

## Base e integridad

Tras las pruebas, una consulta de metadatos precedida por `TestDatabaseGuard::assertConnected` confirmó `fresapos_test` sin tablas: [evidencia](test-db-after.json). No hubo migraciones ni escrituras operativas sobre `fresapos`; no se calcularon hashes de sus filas. Configuración y conexión local intactas por comparación de archivos.

Una revisión automática de permisos falló por límite de uso y no ejecutó el comando. Tras la indicación del usuario de continuar, el reintento fue aprobado. No se eludió la revisión ni se calificó como acción insegura.

Pendientes: navegador autenticado E2E con datos seguros, lectores de pantalla, tacto real, Firefox/Safari, zoom nativo, axe/Lighthouse/INP, Azure SQL/cPanel y suite global repetida en esta etapa. No se crearon credenciales ni datos de negocio para QA visual.

Sintaxis PHP (tres archivos), Node (dos scripts), `git diff --check` y HTTP `/login` + `/up` verificados: [registro](syntax-health.json). El manifiesto excluye su propio archivo, `delivery-check.json` y el índice evolutivo `ESTADO.md`.
