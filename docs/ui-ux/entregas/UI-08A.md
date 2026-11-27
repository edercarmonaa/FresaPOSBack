# Entrega UI-08A · Catálogo, mesas y resumen del POS

## 1. Identificación

UI-08A, 27/11/2026. **Pendiente de aceptación**. UI-07D aceptada mediante «continua», autorizando catálogo, mesas y resumen del POS.

## 2. Objetivo

Modernizar la presentación operativa existente conservando información, permisos, cantidades, notas, estados y acciones de Livewire.

## 3. Alcance autorizado

Dos vistas del POS y una hoja CSS local, pruebas nuevas y documentación. Los paneles financieros se mantienen en un ámbito claro, sin modificar sus vistas, condiciones, claves o acciones. UI-08B no se implementa.

## 4. Estado inicial

Repositorio limpio, HEAD 299c69a862e75494431411ab9b20cd7779502b23. Laravel 13.42, Livewire 4.4.8, PHP 8.3.36. POS con estilos claros embebidos y panel móvil existente. Línea base: 378 pruebas / 1.765 aserciones; 46,164 s, 96 MB. Pint, Larastan, Composer strict y requisitos de plataforma pasan. Integración SQL no ejecutada como línea base previa a los cambios.

## 5. Estado inicial y final de Git

HEAD e índice permanecen sin cambios; 4440 archivos iniciales inventariados. Inicio limpio, sin staging. Cambian tres archivos existentes y se agregan únicamente archivos de UI-08A. Sin commits, pushes, ramas ni despliegues. [Integridad](../qa/ui-08a/source-integrity.json).

## 6. Archivos creados

`public/css/ui/pos.css`; `tests/Feature/PosPresentationTest.php`; `tests/Integration/PosPresentationHttpTest.php`; cuatro copias originales, FixtureData y README bajo `tests/Fixtures/UI08A/`; [guía](../pos-ui08a.md), informe, QA y evidencias. El [manifiesto](../qa/ui-08a/manifest.json) contiene el inventario con SHA-256.

## 7. Archivos modificados

`Modules/Sales/resources/views/pos.blade.php`: contexto de página y carga CSS. `livewire/point-of-sale.blade.php`: clase del módulo, contenedor financiero y temporización visual del enfoque. `docs/ui-ux/ESTADO.md`: resolución de marcadores de merge preexistentes y actualización de aceptaciones. Layout, parciales, script de red, componentes financieros, servicios, modelos y pruebas anteriores permanecen intactos.

## 8. Justificación de cambios

Tokens, Inter local, espacio y foco consistentes; objetivos táctiles de 44 px; colores y estados legibles en ambos temas. El layout heredado conserva su clase clara, pero la página restaura los tokens del tema seleccionado y vuelve a establecer el ámbito claro únicamente dentro de los hijos financieros. El enfoque del encabezado espera 30 ms tras el tick para permitir que la trampa Alpine instalada capture primero el botón de apertura. No modifica escritura o cálculo alguno.

## 9. Evidencias visuales

91 capturas PNG, 54 combinaciones de matriz y estados adicionales. [Antes](../evidencias/ui-08a/before-open-1366.png), [después](../evidencias/ui-08a/after-open-1366.png), [oscuro](../evidencias/ui-08a/chrome-open-dark-1366.png), [mesas móviles](../evidencias/ui-08a/chrome-tables-light-390.png), [panel móvil](../evidencias/ui-08a/chrome-panel-dark-390.png). Son datos ficticios y paneles financieros sustituidos solo en QA; no son capturas de ventas operativas. Las capturas completas pueden mostrar contenido más allá del viewport detrás del diálogo fijo.

## 10. Resultados de pruebas

Comandos ejecutados desde PowerShell en la raíz usando `.\scripts\local.cmd` antes de PHP o Composer.

| Comando | Línea base | Final |
|---|---|---|
| `php vendor/phpunit/phpunit/phpunit --testsuite Feature,Architecture` | 378 / 1.765 | 396 / 1900 |
| `php -d memory_limit=1536M vendor/phpunit/phpunit/phpunit` con los ocho archivos de integración indicados abajo | No ejecutada | 49 / 656 |
| `composer lint` | Pasa | Pasa |
| `composer analyse` | Pasa | Pasa, sin errores |
| `composer validate --strict` | Pasa | Pasa |
| `composer check-platform-reqs` | Pasa | Pasa |

Integración: PointOfSaleTest, PosIdempotencyTest, CheckoutLivewireTest, SaleReasonsWebTest, SaleDiscountsWebTest, SaleCorrectionsWebTest, SaleReceiptsWebTest y PosPresentationHttpTest, todos en `tests/Integration/`. Resultado final sin errores, fallos ni omisiones. [JUnit y logs](../qa/ui-08a/README.md). Los tiempos concurrentes no se utilizan como benchmark. Las pruebas nuevas comparan 64 pares de estados, permisos y disponibilidad de esquema; verifican script y claves byte a byte, parciales, escape, paginación y fuente completa con cambios visuales enumerados. La regresión SQL cubre acciones e idempotencia existentes y el montaje real de componentes.

## 11. Accesibilidad

83 comprobaciones de navegador. Diálogo, enfoque inicial, trampa de foco, Escape, cierre y retorno al botón verificados con Alpine real. Objetivos visibles >=44 px en la matriz. Contraste seleccionado mínimo: texto 5.76:1 y bordes 3.29:1. Los controles deshabilitados usan los tokens existentes y no se contabilizan como texto activo. Fuente raíz al 200 %, tema automático, movimiento reducido y almacenamiento bloqueado comprobados. No constituye una certificación WCAG ni una prueba con lector de pantalla.

## 12. Responsive

Chrome: 2560×1440, 1920×1080, 1366×768, 1024×768, 768×1024, 390×844 y 320×844. Edge: 1366×768 y 390×844. Venta, mesas y vacío en ambos temas: 54 combinaciones, sin desbordamiento global detectado. Paneles móviles y trece estados adicionales revisados en ambos temas. Se mantiene el breakpoint 1024; imágenes y nombres largos se adaptan sin recortar importes. [Matriz CSV](../qa/ui-08a/responsive.csv).

## 13. Rendimiento frontend

Primera muestra concurrente con SQL, cinco recargas por fase: mediana LCP 292→344 ms, CLS 0. Se conserva este resultado, sin atribuir causalidad al CSS. Segunda medición aislada, diez muestras calientes por fase, alternando el orden, Chrome 1366×768: antes mín./mediana/máx. 112/128.0/144 ms; después 160/176.0/228 ms; CLS máximo 0. [Muestras y resumen](../qa/ui-08a/performance-summary.json). No representa tráfico autenticado ni demuestra una mejora causal. Una hoja CSS nueva, sin dependencias nuevas de hosting; el único cambio Alpine de producto es la temporización de foco.

## 14. Integridad

Solo tres diferencias en los 4440 archivos iniciales. Se conservan 204 archivos previos de pruebas y los diez protegidos. Ocho protegidos se comparan con el inventario inicial UI-08A; .env y .env.testing, ignorados por Git, coinciden con la captura protegida hist?rica UI-03 y no fueron editados. Todos los manifiestos anteriores mantienen sus entradas salvo las diferencias preexistentes registradas de UI-02 a UI-05; no hay nuevas alteraciones de sus archivos sellados. UI-06 a UI-07D íntegros. No se consultan ni comparan hashes de filas operativas. SQL de integración limitado por TestDatabaseGuard a fresapos_test, que terminó sin tablas. `/up` y `/login`: 200; `/pos` anónima: 302 hacia login.

## 15. Hallazgos preexistentes

ESTADO.md contenía marcadores de merge ya comprometidos y estados atrasados respecto a las aceptaciones. Se reconcilió el índice vivo sin reescribir informes históricos. Los manifiestos de UI-02 a UI-05 ya reflejaban cambios posteriores autorizados en tokens, tema, prueba excepcional de UI-03, layout y vistas activas de Reportes. No había marcadores de conflicto en el código inspeccionado.

## 16. Incidencias y correcciones

Se corrigieron referencias con saltos de línea distintos, ancho de controles de cantidad, arranque Alpine de la fixture, secuencia del ensayo de diálogo y retorno de foco. La primera comparación de contraste seleccionaba un control deshabilitado: se corrigió la selección a un control activo conservando los tokens compartidos. La comparación DOM se adaptó únicamente a la diferencia explícita de foco, con prueba adicional de fuente completa. Algunas rutas y patrones de inspección fueron corregidos sin mutaciones. [Registro](../qa/ui-08a/incidents.json). Los intentos intermedios no se presentan como aprobados; se conservan logs definitivos y el registro de incidencias, no cada log intermedio.

## 17. Riesgos

La corrección de orden de foco depende de la activación de la trampa de la versión Livewire instalada; debe repetirse la prueba de teclado al actualizarla. La convivencia visual del resumen temático con paneles financieros claros es deliberada hasta UI-08B. Las comparaciones de presentación no sustituyen la regresión financiera completa.

## 18. Limitaciones

Chrome 158.0.8127.37 y Edge 158.0.4370.45, ambos Chromium headless. Fixtures sin hidratación Livewire ni script operativo `$wire`; sí ejecutan Alpine real y solo GET. Las pruebas SQL montan los componentes reales, pero no equivalen a un recorrido autenticado de navegador. Los textos y números de QA son ficticios. Se usa Node únicamente como herramienta de QA ya instalada.

## 19. Verificaciones no ejecutadas

Suite global completa, suites de concurrencia/estrés, navegador autenticado extremo a extremo, lector de pantalla, auditoría WCAG integral, axe/Lighthouse/INP, Firefox/Safari, dispositivos táctiles y zoom nativo: pendientes fuera de esta subetapa. Fuente raíz al doble sí probada. Azure SQL y cPanel no evaluados. No se evalúan visualmente tickets, PDF o XLSX. No se preparan datos operativos en fresapos.

## 20. Criterios de aceptación

Temas, responsive, controles, foco móvil y contraste seleccionado: verificados en el alcance descrito. Acciones, permisos, claves, límites, cantidades, notas, importes y guardas: conservados por comparación y regresión seleccionada. PHPUnit, Pint y Larastan pasan; Composer y plataforma pasan. Índice Git y archivos protegidos se conservan. La aceptación definitiva corresponde al responsable.

## 21. Recomendaciones

Revisar `/pos` con una cuenta autorizada y completar el recorrido asistido con lector de pantalla. Próxima propuesta: UI-08B, presentación de cobro y paneles de operaciones, descuentos y correcciones, con regresión financiera específica y sin alterar reglas de negocio. Requiere autorización antes de iniciarse.

## 22. Propuesta de estado final

**Pendiente de aceptación** con las limitaciones documentadas. UI-08A termina aquí. No se inicia UI-08B automáticamente.
