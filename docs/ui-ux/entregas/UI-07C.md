# Entrega UI-07C · Caja

## 1. Identificación

UI-07C, 27/11/2026. **Pendiente de aceptación**. UI-07B aceptada al responder «continua» a la propuesta de continuar con la presentación de Caja.

## 2. Objetivo

Uniformar la presentación de apertura, movimientos, arqueo e historial sin alterar el comportamiento financiero ni las restricciones de acceso.

## 3. Alcance autorizado

Página de Caja y sus tres vistas Livewire, CSS local, pruebas nuevas y evidencias. El usuario autorizó Caja como UI-07C; el inventario histórico de UI-00 la agrupaba dentro de UI-08.3 y se conserva sin reescribirlo. No se implementan pantallas nuevas ni se modifica el POS, la API o los tickets.

## 4. Estado inicial

CSS inline, superficies blancas y estilos anteriores en Caja. UI-06, UI-07A y UI-07B seguían sin commit. Línea base Feature + Architecture: 328 pruebas / 1.367 aserciones, 85,648 s y 92 MB. Pint, Larastan, Composer strict y requisitos de plataforma pasaron. No se ejecutó integración SQL antes de editar. [Calidad](../qa/ui-07c/quality.json).

## 5. Estado inicial y final de Git

HEAD permanece `be38e26ae54e928f8567a8260ea09bfda193fd2d`, rama main. Inventario inicial: 3.907 archivos con seguimiento y sin seguimiento, incluidos los cambios de etapas previas. Al cerrar se detectó staging concurrente de 608 archivos correspondientes a esos cambios anteriores; su origen no se atribuye desde esta tarea. Los cuatro archivos de Caja permanecen sin preparar y el índice vivo tiene diferencias preparadas y no preparadas. Se registró el contenido del índice y se verificó que permaneciera igual desde la observación. Esta tarea no ejecutó staging, reset, commits, pushes, ramas ni despliegues. [Registro del índice](../qa/ui-07c/concurrent-index.json), [integridad](../qa/ui-07c/source-integrity.json).

## 6. Archivos creados

`public/css/ui/cash.css`; `tests/Feature/CashPresentationTest.php`; `tests/Integration/CashPresentationHttpTest.php`; cuatro copias previas, FixtureData y README en `tests/Fixtures/UI07C/`; [guía](../caja-ui07c.md), informe, QA y evidencias de esta subetapa. El manifiesto enumera archivos y huellas de entrega.

## 7. Archivos modificados

Cuatro vistas de `Modules/Cash/resources/views/`: desk y livewire/cash-desk, livewire/movements-panel y livewire/closing-panel. También `docs/ui-ux/ESTADO.md`, índice vivo que registra aceptación de UI-07B y estado de UI-07C. No cambian layout, componentes compartidos, navegación ni clases Livewire desde el inicio de esta subetapa.

## 8. Justificación de cambios

Retirar CSS inline y utilizar tokens de tema, controles compartidos, superficies y disposición responsive. Formularios nativos y mensajes originales. Los estados generales son informativos; pendientes y desconexión, advertencias. El resumen usa superficie neutral y conserva el texto de cuadrada/faltante/sobrante y la diferencia firmada. No hay cálculos, formateos monetarios nuevos ni operaciones adicionales.

## 9. Evidencias visuales

171 PNG: 162 combinaciones de la matriz, comparativa antes/después, estados de red, fuente al doble, concepto largo, vacíos y una captura histórica de la incidencia de QA. [Antes](../evidencias/ui-07c/before-open-1366.png), [después](../evidencias/ui-07c/after-open-1366.png), [arqueo móvil](../evidencias/ui-07c/chrome-blind-light-390.png), [faltante en oscuro](../evidencias/ui-07c/chrome-closed-dark-1366.png). Datos ficticios rotulados y sin hidratación Livewire. [Matriz](../qa/ui-07c/responsive.csv).

## 10. Resultados de pruebas

Ejecutados desde PowerShell en la raíz con `.\scripts\local.cmd` antes de los comandos PHP/Composer.

| Comando después del wrapper | Línea base | Final |
|---|---|---|
| `php vendor/phpunit/phpunit/phpunit --testsuite Feature,Architecture` | 328 / 1.367 | 358 / 1.433; 182,061 s; 94 MB |
| `php vendor/phpunit/phpunit/phpunit tests/Feature/CashPresentationTest.php` | No existía | 30 / 66 tras corregir el helper de prueba |
| `php vendor/phpunit/phpunit/phpunit tests/Integration/CashTest.php tests/Integration/CashHttpTest.php tests/Integration/CashFinanceTest.php tests/Integration/CashFinanceHttpTest.php tests/Integration/CashFinanceLivewireTest.php tests/Integration/CashReconciliationTest.php tests/Integration/CashReconciliationHttpTest.php tests/Integration/CashReconciliationLivewireTest.php tests/Integration/CashPresentationHttpTest.php` | No ejecutada | 66 / 1.075; 135,425 s; 82 MB |
| `composer lint` | Pasa | Pasa |
| `composer analyse` | Pasa | Pasa, sin errores |
| `composer validate --strict` | Pasa | Pasa |
| `composer check-platform-reqs` | Pasa | Pasa |

Las ejecuciones PHPUnit finales indicadas no tienen errores, fallos ni omisiones. Se conserva el primer intento de pruebas nuevas: 30 pruebas, 29 errores por helper Mockery no disponible, corregido sin instalar dependencias. La ejecución final de Feature/Architecture coincidió con QA de navegador; su duración no constituye un benchmark comparable con la línea base. [Logs y JUnit](../qa/ui-07c/README.md).

## 11. Accesibilidad

31 comprobaciones explícitas de navegador; labels, atributos required, disabled, aria-invalid, roles y anuncios originales se conservan. Foco visible y recorrido Tab de conteo a observación verificados. Objetivos visibles de controles y enlaces de al menos 44 px en la matriz. 26 pares de contraste: mínimo textual 5,76:1 y borde de control 3,29:1. Cabecera de tabla disponible semánticamente en móvil. No se declara conformidad WCAG completa ni compatibilidad comprobada con lectores de pantalla.

## 12. Responsive

Chrome en 2560×1440, 1920×1080, 1366×768, 1024×768, 768×1024, 390×844 y 320×844. Edge en 1366×768 y 390×844. Nueve estados y ambos temas: 162 combinaciones sin desbordamiento global ni controles pequeños detectados. Fuente raíz al 200 % y concepto de 200 caracteres continuos revisados a 320 px. [Resumen](../qa/ui-07c/browser-summary.json).

## 13. Rendimiento frontend

Fixture estática local de sesión abierta, Chrome, 1366×768; cinco recargas calientes por fase. LCP antes mín./mediana/máx.: 176/192/208 ms; después: 160/164/176 ms. CLS máximo 0 en ambas fases. La muestra no prueba mejora causal ni representa producción, red móvil o Livewire autenticado. Solo se añade una hoja CSS de Caja; no se añade JavaScript de negocio ni dependencia.

## 14. Integridad

Los archivos iniciales fuera de las cuatro vistas y el índice vivo conservan sus hashes; esto incluye 190 archivos existentes bajo pruebas y los diez protegidos. Manifiestos de UI-06 (126 entradas), UI-07A (246) y UI-07B (229): íntegros. Las tres vistas internas son idénticas al original al excluir clases, CSS inline y espacios; script operativo e hijos se comparan además byte por byte. Sin cambios en rutas, servicios, modelos, migraciones, dependencias, `.env`, conexión ni autenticación. Inspección guardada de `fresapos_test`: cero tablas. No se calculan hashes de filas operativas. `/up` y `/login` locales responden 200; `/cash` anónima responde 302 hacia login.

## 15. Hallazgos preexistentes

La línea base ejecutada no tuvo fallos. Las garantías de conteo ciego, observación obligatoria, permisos, claves durables y consulta de operaciones pendientes ya existían y se conservan. El historial autorizado de turnos anteriores mantiene sus importes durante el conteo; no corresponde al saldo de la sesión que se está contando. No existe una interfaz completa de CRUD de usuarios y roles para modernizar en este alcance.

## 16. Incidencias y correcciones

El helper inicial de las pruebas nuevas usó Mockery ausente en el proyecto: se reemplazó por un doble local para Schema. La primera matriz pasó controles automáticos, pero la inspección manual detectó el indicador diferido visible en la fixture sin hidratación. Se corrigió solo el exportador y se repitieron las 162 combinaciones; tres comprobaciones adicionales verifican loading/loading.delay/offline ocultos en reposo. Un chequeo de cierre esperaba índice Git vacío y detectó el staging concurrente: se registró y preservó, sin revertirlo ni atribuirlo al usuario. No se modificó ninguna prueba existente. Se conservan resultados de los intentos y la captura intermedia en QA.

## 17. Riesgos

Los controles financieros reales se validan por las pruebas SQL y Livewire existentes, mientras las capturas son estáticas. Sigue pendiente la revisión visual autenticada extremo a extremo. El staging concurrente contiene trabajo de etapas anteriores y debe revisarse de forma independiente antes de cualquier commit; esta entrega no lo altera.

## 18. Limitaciones

Chrome 158.0.8127.37 y Edge 158.0.4370.45 comparten Chromium y se ejecutaron headless. Las fixtures no permiten operar ni sirven para validar conectividad real o permisos de una cuenta. La adaptación visual mantiene las estructuras y mensajes existentes; no incorpora nuevos flujos ni cambia la confirmación del cierre.

## 19. Verificaciones no ejecutadas

Suite global completa y tres clases de concurrencia de Caja: no repetidas en esta subetapa de presentación; sí las suites y nueve clases relacionadas indicadas. Navegador autenticado completo, lector de pantalla, auditoría WCAG integral, axe/Lighthouse/INP, Firefox/Safari, dispositivos táctiles y zoom nativo: pendientes. Azure SQL y cPanel: no evaluados. No se crean escenarios en `fresapos` ni se verifica su integridad mediante hashes de filas.

## 20. Criterios de aceptación

Temas, responsive, foco, contraste seleccionado y visualización de estados: verificados dentro del alcance. Conteo ciego, bloqueos, recuperación, precisión recibida, permisos y regresión seleccionada: conservados y con pruebas satisfactorias. Pint y Larastan pasan. Integridad de fuentes y entregas previas contrastada; índice concurrente preservado. Los pendientes y límites impiden declarar una validación universal o aceptación definitiva sin el responsable.

## 21. Recomendaciones

Revisar la pantalla real con una cuenta autorizada, completar evaluación con lector de pantalla y revisión visual autenticada. Propuesta siguiente: UI-07D, presentación de Reportes y sus estados existentes, excluyendo exportaciones PDF/XLSX. No crear CRUD de usuarios/roles inexistente ni iniciar el módulo siguiente sin autorización.

## 22. Propuesta de estado final

**Pendiente de aceptación**, con las limitaciones documentadas. UI-07C termina aquí. UI-07D no se inicia automáticamente.
