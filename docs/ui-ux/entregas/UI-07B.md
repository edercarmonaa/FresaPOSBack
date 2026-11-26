# Entrega UI-07B · Motivos autorizados de ventas

## 1. Identificación

UI-07B, 27/11/2026. **Pendiente de aceptación**. UI-07A aceptada al responder «continua» a la propuesta de continuar con Motivos.

## 2. Objetivo

Modernizar la presentación de la administración de motivos conservando las operaciones y garantías existentes.

## 3. Alcance autorizado

Dos vistas de Motivos, una hoja CSS local, pruebas nuevas, fixtures y documentación. Formulario en línea, filtros, tarjetas, paginación y mensajes. No se modifica la lógica Livewire ni las pantallas de retiro o cancelación del POS.

## 4. Estado inicial

UI-06 y UI-07A sin commit. Motivos usaba CSS inline y superficies blancas. Feature + Architecture: 319 pruebas / 1.349 aserciones, 77,234 s, 92 MB. Pint, Larastan, Composer strict y requisitos de plataforma pasaron. Integración SQL no ejecutada antes de editar. [Resultados reales](../qa/ui-07b/quality.json).

## 5. Estado inicial y final de Git

HEAD permanece `be38e26ae54e928f8567a8260ea09bfda193fd2d`, rama main. Sin staging, commits, pushes, ramas ni despliegues. Se preservan los cambios previos y los archivos no versionados de UI-06 y UI-07A. El inventario inicial de 3.678 archivos y sus hashes se comparan contra el cierre. [Integridad](../qa/ui-07b/source-integrity.json).

## 6. Archivos creados

`public/css/ui/reasons.css`; `tests/Feature/ReasonsPresentationTest.php`; `tests/Integration/ReasonsPresentationHttpTest.php`; dos vistas anteriores, FixtureData y README en `tests/Fixtures/UI07B/`; [guía](../motivos-ui07b.md), este informe, QA y evidencias exclusivos de UI-07B. El manifiesto enumera todos los archivos de entrega y sus huellas.

## 7. Archivos modificados

`Modules/Sales/resources/views/reasons.blade.php`, `Modules/Sales/resources/views/livewire/reason-administration.blade.php` y el índice vivo `docs/ui-ux/ESTADO.md`. El layout y los componentes compartidos permanecen intactos desde el inicio de esta subetapa.

## 8. Justificación

Reutilizar clases de controles y acciones sin transformar sus elementos HTML; retirar CSS inline y aplicar tokens a todos los estados. Formulario a dos columnas en escritorio, una en móvil. Estado genérico informativo para no presentar una intención sin confirmar como éxito. Textos, condiciones y orden preservados.

## 9. Evidencias visuales

151 PNG, incluidas capturas históricas y adicionales. Matriz principal: 126 combinaciones; revisión final del mensaje informativo: 18. [Antes](../evidencias/ui-07b/before-list-1366.png), [después](../evidencias/ui-07b/after-list-1366.png), [edición oscura](../evidencias/ui-07b/chrome-edit-dark-1366.png), [estado enviado final móvil](../evidencias/ui-07b/review-chrome-submitted-light-390.png). Datos ficticios explícitos. [Matriz](../qa/ui-07b/responsive.csv).

## 10. Resultados de pruebas

Todos los comandos se ejecutaron en PowerShell desde la raíz, usando `.\scripts\local.cmd`.

| Comando después del wrapper | Línea base | Final |
|---|---|---|
| `php vendor/phpunit/phpunit/phpunit --testsuite Feature,Architecture` | 319 / 1.349 | 328 / 1.367; 90,808 s; 92 MB |
| `php vendor/phpunit/phpunit/phpunit tests/Feature/ReasonsPresentationTest.php` | No existía | 9 / 18 |
| `php vendor/phpunit/phpunit/phpunit tests/Integration/SaleReasonsTest.php tests/Integration/SaleReasonsHttpTest.php tests/Integration/SaleReasonsWebTest.php tests/Integration/ReasonsPresentationHttpTest.php` | No ejecutada | 36 / 451; 106,430 s; 72 MB |
| `composer lint` | Pasa | Pasa tras corregir formato de dos scripts nuevos de QA |
| `composer analyse` | Pasa | Pasa, sin errores |
| `composer validate --strict` | Pasa | Pasa |
| `composer check-platform-reqs` | Pasa | Pasa |

No hubo errores, fallos ni omisiones en las ejecuciones PHPUnit indicadas. Se incluyen los nueve estados de presentación, escape, atributos de controles, labels, fieldsets, confirmaciones, recuperación, middleware, permisos y carga única de recursos. [Logs y JUnit](../qa/ui-07b/README.md).

## 11. Accesibilidad

Se conservan asociaciones de labels y roles nativos status/alert. Tab desde nombre llega a descripción; código y operación permanecen fuera de edición. Objetivos visibles de controles de al menos 44 px en la matriz, foco visible, ajuste con fuente raíz al 200 %, tema automático y movimiento reducido verificados. 22 pares medidos: texto mínimo 5,76:1 y borde de control mínimo 3,29:1. No se declara conformidad WCAG completa ni revisión con lector de pantalla.

## 12. Responsive

Chrome: 2560×1440, 1920×1080, 1366×768, 1024×768, 768×1024, 390×844 y 320×844. Edge: 1366×768 y 390×844. Ambos temas. Sin desbordamiento global ni controles pequeños detectados en las 144 combinaciones principales y de revisión. Textos de 64/150/500 caracteres sin cortes de página a 320 px. [Detalle](../qa/ui-07b/browser-summary.json).

## 13. Rendimiento

Fixture local de listado, Chrome, 1366×768, cinco recargas calientes por fase. LCP antes mín./mediana/máx. 96/124/192 ms; después 112/112/144 ms. CLS 0 en ambas fases. La muestra no demuestra mejora causal ni equivale al rendimiento de producción o de Livewire autenticado. Se añade una sola hoja CSS; no hay JavaScript de módulo nuevo.

## 14. Integridad

Los 184 archivos existentes bajo los directorios de pruebas y los diez protegidos se contrastaron con hashes. Los manifiestos de UI-06 (126 entradas) y UI-07A (246 entradas) permanecen íntegros. Sin cambios en backend, rutas, permisos, dependencias, conexión local ni autenticación. Solo las pruebas existentes y la nueva clase SQL actúan en `fresapos_test`; inspección posterior guardada: cero tablas. No se calculan hashes de filas operativas ni se afirma esa validación. `/up` y `/login` locales responden 200 por GET.

## 15. Hallazgos preexistentes

La línea base ejecutada no tuvo fallos. La pantalla ya usa un resumen de errores y confirmación nativa de Livewire; se mantienen. La revisión visual no convierte ese resumen en una validación nueva por campo. La base operativa no se utiliza para preparar escenarios de QA.

## 16. Incidencias y correcciones

Primera revisión visual automática sin infracciones; inspección manual detectó offline visible en reposo por ausencia de hidratación en la fixture. Se ocultó junto con loading solo en QA y se repitió la matriz. Se conserva evidencia del intento. Pint final señaló formato exclusivamente en exportador y router nuevos; corrección acotada y repetición global satisfactoria. Revisión manual del tono de estado llevó a usar información azul y repetir las 18 combinaciones afectadas y los contrastes. Ninguna prueba existente se modificó. Durante inspección se corrigieron rutas supuestas inexistentes; una imagen se solicitó antes de generarse y luego se revisó al estar disponible.

## 17. Riesgos

La composición real de Livewire se verifica en las pruebas SQL, mientras las capturas usan renderizado estático. Esa separación deja pendiente la evaluación visual autenticada extremo a extremo. No usar las fixtures para operar ni como comprobación de conectividad o permisos de un usuario real.

## 18. Limitaciones

Chrome 158.0.8127.37 y Edge 158.0.4370.45, ambos Chromium, en ejecución headless. Las capturas originales del estado enviado preceden al último ajuste de tono; las `review-*` contienen su apariencia final. No se alteran ni modernizan todavía Caja, POS u otros módulos.

## 19. Verificaciones no ejecutadas

Suite global completa fuera del alcance seleccionado: no repetida por su duración; sí se ejecutaron Feature, Architecture y cuatro clases de integración relacionadas. Navegador autenticado completo, lector de pantalla, auditoría WCAG integral, axe/Lighthouse/INP, Firefox/Safari, dispositivos táctiles y zoom nativo: pendientes, sin evidencia de ejecución. Azure SQL y cPanel: no evaluados en esta subetapa local.

## 20. Criterios de aceptación

Presentación consistente y temas: verificados en el alcance descrito. Responsive y controles: verificados con las limitaciones anteriores. Contratos, recuperación, confirmación, permisos y regresión seleccionada: pasan. Pint y Larastan: pasan. Integridad de fuentes previas: contrastada por hashes. La aceptación definitiva depende del responsable; los pendientes se mantienen explícitos.

## 21. Recomendaciones

Revisar la pantalla real con una cuenta autorizada y evaluar con lector de pantalla. Propuesta siguiente: UI-07C, presentación de Caja, con inspección de su alcance antes de editar. No iniciar sin autorización.

## 22. Propuesta de estado final

**Pendiente de aceptación**, con las limitaciones documentadas. UI-07B termina aquí; los siguientes módulos no se ejecutan automáticamente.
