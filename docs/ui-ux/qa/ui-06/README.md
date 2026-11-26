# QA UI-06 · Componentes compartidos

27/11/2026. Inicio limpio en `main`, commit `be38e26`. Solo dos archivos inicialmente versionados cambian: el índice evolutivo y una pila de scripts del layout. Ningún módulo o componente previo cambia. [Integridad](source-integrity.json): 3.309 archivos comprobados, diez protegidos y 178 archivos de pruebas —incluidas copias históricas— intactos.

## Pruebas ejecutadas

En PowerShell, desde la raíz:

```powershell
.\scripts\local.cmd php -d memory_limit=1536M vendor/phpunit/phpunit/phpunit tests/Feature tests/Architecture --log-junit .tools/ui06-baseline.xml
.\scripts\local.cmd php vendor/phpunit/phpunit/phpunit tests/Feature/AdminComponentsTest.php
.\scripts\local.cmd php -d memory_limit=1536M vendor/phpunit/phpunit/phpunit tests/Feature tests/Architecture --log-junit .tools/ui06-final.xml
.\scripts\local.cmd php -d memory_limit=1536M vendor/phpunit/phpunit/phpunit tests/Feature tests/Architecture --log-junit .tools/ui06-post-fix.xml
.\scripts\local.cmd composer lint
.\scripts\local.cmd composer analyse
.\scripts\local.cmd composer validate --strict
.\scripts\local.cmd composer check-platform-reqs
```

| Ejecución | Resultado real |
|---|---|
| Línea base Feature + Architecture | 305 pruebas / 1.227 aserciones; 97,238 s; 90 MB |
| Nueva clase | 7 / 60; 29,095 s; 48 MB |
| Final inicial | 312 / 1.287; 57,743 s; 90 MB |
| Después de corregir CSS/JS | 312 / 1.287; 30,253 s; 90 MB |

Cero errores, fallos u omitidas en esas ejecuciones PHPUnit. Pint, Larastan y Composer estricto/plataforma pasan antes y después. Tras las correcciones se repiten PHPUnit, Pint y Larastan, todos código cero. [Logs, JUnit y códigos](quality.json). No se actualizaron dependencias o pruebas existentes.

Estrategia de UI-06: componentes optativos todavía fuera de módulos. No se ejecutaron integración SQL, exportaciones ni E2E de negocio; no hay cambios en sus vistas, consultas o servicios. La suite global de otras áreas queda sin repetir. No se atribuyen resultados históricos a esta etapa.

## Catálogo y navegador

```powershell
.\scripts\local.cmd php docs/ui-ux/qa/ui-06/export-catalog.php
.\scripts\local.cmd php -S 127.0.0.1:8096 docs/ui-ux/qa/ui-06/router.php
```

En otra consola, con herramientas QA preexistentes, sin instalar:

```powershell
& 'C:/Users/Usuario/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/bin/node.exe' docs/ui-ux/qa/ui-06/browser-check.cjs
& 'C:/Users/Usuario/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/bin/node.exe' docs/ui-ux/qa/ui-06/additional-checks.cjs
```

El exportador elimina conexiones SQL y usa sesión array; el catálogo no contiene credenciales o datos operativos. El router solo sirve GET de recursos enumerados y no inicia Laravel. La matriz bloquea cualquier petición ajena al origen o distinta de GET. El formulario documental impide su envío; el controlador de producto no intercepta submit.

Chrome 158.0.8127.37: siete referencias, 2560×1440, 1920×1080, 1366×768, 1024×768, 768×1024, 390×844 y 320×844. Edge 158.0.4370.33: escritorio y 390×844. En claro/oscuro se capturan catálogo, modal y drawer: 54 casos. Cuatro capturas adicionales (notificación flotante, fuente raíz duplicada y dos ventanas de 390×320): 58 PNG.

Resultado final: sin desbordamiento de página, diálogos fuera del viewport, IDs duplicados, controles sin nombre, controles inferiores a 44 px o errores de consola. Ochenta y siete comprobaciones de foco inicial, Escape, retorno, teclado, cierre, almacenamiento bloqueado, tema automático, densidad y reflujo pasan. Siete adicionales pasan: ventanas bajas y footer alcanzable, submits ignorados, exclusión de otros diálogos nativos y carga repetida del script.

Veinticuatro pares de contraste cumplen: texto mínimo 5,86:1, bordes 3,29:1. La ampliación duplica la fuente raíz; no es zoom nativo. [Resumen](browser-summary.json), [matriz](responsive.csv), [resultado completo](../../evidencias/ui-06/browser-results.json), [adicionales](additional-results.json).

Inspección visual: catálogo claro de escritorio, modal claro móvil, drawer oscuro a 320 px y corrección del ancho móvil. Se conservan texto y controles dentro del diálogo, con contenido desplazable en ventana corta. La tabla se desplaza localmente sin esconder columnas.

## Incidencias corregidas

La línea base tardó más que las anteriores; terminó satisfactoriamente sin intervención. No se aplicaron cambios antes de que terminara.

Primer recorrido: 54 capturas, una comprobación de foco fallida. La navegación secuencial nativa podía salir del modal. La inspección visual detectó además un modal de 400 px en viewport de 390 px por box-sizing content-box. [Resultado inicial](browser-attempt-01.json), [log](browser-attempt-01.log), [captura](modal-attempt-01.png).

Correcciones: border-box en contenedores nuevos y contención explícita de Tab/Shift+Tab solo en diálogos UI-06. Se añadió verificación automática de límites y se repitió la matriz completa, satisfactoria. [Geometría final](dialog-geometry-final.log): x=16, ancho=358 en viewport=390. No se modificaron componentes ni controladores previos para resolverlo.

## Rendimiento y límites

Catálogo nuevo, Chrome headless, 1366×768, sin throttling, cinco navegaciones: LCP mínimo/mediana/máximo 92/128/164 ms; CLS 0. No existe una pantalla anterior equivalente para comparación causal. CSS adicional: 3.731 bytes; JS: 2.906 bytes, sin comprimir. Ninguno se solicita en páginas sin los nuevos componentes. No hay dependencias ni fuentes nuevas.

Pendientes: integración en módulos y regresión de sus flujos en UI-07; E2E autenticado, lectores de pantalla, Firefox/Safari, tacto real, zoom nativo, axe/Lighthouse/INP, suite global, Azure SQL y cPanel. No se certifica WCAG integral. No se realizaron migraciones, pruebas financieras o escrituras deliberadas de negocio sobre fresapos; no se comprobaron hashes de sus filas.

[Sintaxis y disponibilidad](syntax-health.json): PHP/Node correctos, diff limpio, `/up`, `/login` y catálogo responden 200. El manifiesto excluye su propio archivo, `delivery-check.json` y el índice evolutivo `ESTADO.md`.
