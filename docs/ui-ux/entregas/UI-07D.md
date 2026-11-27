# Entrega UI-07D · Reportes

## 1. Identificación

UI-07D, 27/11/2026. **Pendiente de aceptación**. UI-07C aceptada al responder «contiua» a la propuesta de continuar con Reportes.

## 2. Objetivo

Completar el refinamiento visual administrativo de las nueve variantes y los errores web, preservando resultados, filtros, permisos, paginación y exportación.

## 3. Alcance autorizado

`/reports`, `/reports/{report}` y error web: dos vistas, CSS adicional, pruebas nuevas y evidencias. Se reutiliza la presentación de UI-05. No se modifican plantillas PDF, generación XLSX, servicios, consultas, rutas, recursos de API ni JavaScript funcional.

## 4. Estado inicial

Repositorio limpio tras un merge nuevo, con entregas UI-06 a UI-07C presentes. Reportes ya tenía tokens, indicadores y estilos de UI-05. Línea base Feature + Architecture: 358 pruebas / 1.433 aserciones, 48,685 s y 96 MB. Pint, Larastan, Composer strict y requisitos de plataforma pasaron. Integración SQL no ejecutada antes de editar. [Resultados](../qa/ui-07d/quality.json).

## 5. Estado inicial y final de Git

HEAD permanece `6e837359751a00f356ffb6a24bd3234cd951c24e`, rama main, merge existente al iniciar. Inventario inicial: 4.169 archivos. Índice sin cambios y sin staging. Esta tarea no crea commits, pushes, ramas ni despliegues. Los commits y el merge observados al inicio son ajenos a esta subetapa. [Integridad](../qa/ui-07d/source-integrity.json).

## 6. Archivos creados

`public/css/ui/report-admin.css`; `tests/Feature/ReportAdministrationPresentationTest.php`; `tests/Integration/ReportAdministrationPresentationHttpTest.php`; dos vistas originales, FixtureData y README en `tests/Fixtures/UI07D/`; [guía](../reportes-ui07d.md), informe, QA y evidencias de UI-07D. El manifiesto enumera las huellas de entrega.

## 7. Archivos modificados

`Modules/Reports/resources/views/index.blade.php` y `error.blade.php`: contexto CSS, carga de la hoja adicional y clases de controles compartidos. `docs/ui-ux/ESTADO.md`: recuperación del avance y aceptaciones registradas. El archivo `public/css/ui/reports.css` de UI-05, el layout y las pruebas existentes permanecen intactos.

## 8. Justificación de cambios

Ordenar las nueve opciones con distribución estable, uniformar controles y foco y mejorar legibilidad de columnas sin truncar importes. En escritorio, las tablas anchas usan su región existente con scroll de teclado; en móvil se conserva la presentación vertical de UI-05 y se corrige el cálculo visual del ancho de celdas mediante box-sizing. El error web conserva mensaje, rol y retorno.

## 9. Evidencias visuales

177 PNG: 162 combinaciones de la matriz, estados adicionales, comparación antes/después, fuente al doble, producto largo y una captura intermedia de navegación. [Antes](../evidencias/ui-07d/before-sales-1366.png), [después](../evidencias/ui-07d/after-sales-1366.png), [resumen móvil](../evidencias/ui-07d/chrome-summary-light-390.png), [ventas oscuras](../evidencias/ui-07d/chrome-sales-dark-2560.png), [error](../evidencias/ui-07d/error-validation-light-390.png). Fixtures con datos ficticios, separadas de la aplicación operativa. [Matriz](../qa/ui-07d/responsive.csv).

## 10. Resultados de pruebas

Comandos ejecutados desde PowerShell en la raíz, usando `.\scripts\local.cmd` antes de PHP/Composer.

| Comando después del wrapper | Línea base | Final |
|---|---|---|
| `php vendor/phpunit/phpunit/phpunit --testsuite Feature,Architecture` | 358 / 1.433 | 378 / 1.765; 86,891 s; 96 MB |
| `php vendor/phpunit/phpunit/phpunit tests/Feature/ReportAdministrationPresentationTest.php` | No existía | 20 / 332; 6,550 s; 52 MB |
| `php -d memory_limit=1536M vendor/phpunit/phpunit/phpunit tests/Integration/OperationalReportsTest.php tests/Integration/ReportsHttpTest.php tests/Integration/ReportExportsTest.php tests/Integration/ReportAdministrationPresentationHttpTest.php` | No ejecutada | 50 / 784; 236,088 s; 106 MB |
| `composer lint` | Pasa | Pasa |
| `composer analyse` | Pasa | Pasa, sin errores |
| `composer validate --strict` | Pasa | Pasa |
| `composer check-platform-reqs` | Pasa | Pasa |

Las ejecuciones PHPUnit indicadas no tienen errores, fallos ni omisiones. Las pruebas nuevas comparan 162 pares de render anterior/actual: nueve variantes, dos estados del permiso de exportación y nueve escenarios de filas/paginación/rango. Incluyen fronteras 300/301 y 1000/1001, contenido financiero, atributos SVG, formularios, escape y script byte por byte. La regresión existente comprueba lectura sin mutaciones, permisos vigentes, SQL saneado y exportaciones reales sin modificar los exportadores. Los tiempos de la ejecución final de PHPUnit coincidieron con QA de navegador y no son un benchmark comparable con la línea base. [Logs y JUnit](../qa/ui-07d/README.md).

## 11. Accesibilidad

36 comprobaciones explícitas en navegador. Labels nativos, foco visible, teclado en la región de tabla, cabeceras scope y aria-current conservados. Objetivos visibles de controles y enlaces de al menos 44 px en la matriz. 24 pares de contraste medidos: mínimo textual 6,29:1 y borde de control 3,29:1. Fuente raíz al 200 %, tema automático, movimiento reducido y almacenamiento bloqueado verificados. No se declara conformidad WCAG completa ni prueba con lector de pantalla.

## 12. Responsive

Chrome: 2560×1440, 1920×1080, 1366×768, 1024×768, 768×1024, 390×844 y 320×844. Edge: 1366×768 y 390×844. Nueve reportes y ambos temas: 162 combinaciones sin desbordamiento global ni controles pequeños detectados. El scroll horizontal en escritorio queda contenido en la región de resultados; las celdas móviles conservan todos sus valores. Producto continuo de 200 caracteres sin desbordamiento global a 320 px. [Resumen](../qa/ui-07d/browser-summary.json).

## 13. Rendimiento frontend

Fixture local de Ventas en Chrome, 1366×768; cinco recargas calientes por fase. LCP antes mín./mediana/máx.: 208/224/272 ms; después: 176/176/224 ms. CLS máximo 0 en ambas fases. La muestra no demuestra mejora causal ni representa usuarios reales o consultas autenticadas. Se añade una hoja CSS local; no hay JavaScript nuevo de producto ni dependencias de hosting.

## 14. Integridad

Hashes de los 4.169 archivos iniciales: solo cambian las dos vistas y el índice vivo. Los 198 archivos existentes bajo pruebas y los diez protegidos permanecen iguales. UI-06 (126 entradas), UI-07A (246), UI-07B (229) y UI-07C (264) conservan íntegros sus manifiestos. UI-05 ya tenía una diferencia en el layout por UI-06; ahora sus dos vistas activas de Reportes cambian con autorización, mientras sus copias y evidencias históricas se conservan. Las vistas son idénticas al excluir clases, contexto CSS, enlace de la hoja nueva y espacios; no cambian condiciones, cálculos o scripts. `fresapos_test` quedó sin tablas. No se calcularon hashes de filas operativas. `/up` y `/login` responden 200; `/reports` anónima responde 302 hacia login.

## 15. Hallazgos preexistentes

La línea base no tuvo fallos. El índice vivo había vuelto a UI-04 pendiente y UI-05/siguientes sin iniciar, aunque las entregas posteriores estaban presentes; se actualizó a las aceptaciones registradas, sin reescribir sus informes históricos. La diferencia del layout respecto al manifiesto UI-05 corresponde al cambio posterior de UI-06 y no se introduce en UI-07D. No se crea un CRUD de usuarios o roles inexistente.

## 16. Incidencias y correcciones

La primera matriz no detectó infracciones automáticas; la inspección manual mostró una opción aislada en navegación de pantalla amplia. Se ajustó la rejilla y se repitieron las 162 combinaciones con la hoja final. Se conservan el primer resultado y una captura intermedia. No hubo fallos en las pruebas o herramientas de calidad ejecutadas. Una ruta de helper SQL supuesta durante inspección no existía; se identificó el soporte real ReceiptsSqlServerTestCase sin modificarlo.

## 17. Riesgos

Las capturas son estáticas y no sustituyen una sesión autenticada completa. Los filtros y límites se validan por comparación DOM, ejecución del script original sin envío y pruebas SQL reales. El ancho de tablas grandes requiere scroll local en escritorio; se verificó con teclado y se conserva todo el contenido.

## 18. Limitaciones

Chrome 158.0.8127.37 y Edge 158.0.4370.45, ambos Chromium headless. Las cifras de QA son ficticias y no representan el negocio; los escenarios de límite usan conteos totales y muestras de filas. No se modifican ni evalúan visualmente PDF, XLSX o tickets. La disponibilidad real de un reporte sigue perteneciendo a sus permisos del servidor.

## 19. Verificaciones no ejecutadas

Suite global completa, ReportsPerformanceTest y ReportsConcurrencyTest: no repetidos en esta subetapa visual; sí Feature, Architecture y cuatro clases de integración relacionadas. Navegador autenticado extremo a extremo, lector de pantalla, auditoría WCAG integral, axe/Lighthouse/INP, Firefox/Safari, dispositivos táctiles y zoom nativo: pendientes. Azure SQL y cPanel: no evaluados. No se preparan datos en `fresapos` ni se verifica su contenido mediante hashes de filas.

## 20. Criterios de aceptación

Temas, navegación, responsive, foco y contraste seleccionado: verificados en el alcance descrito. Datos, filtros, paginación, JavaScript y formatos recibidos: conservados. Límites, permisos, lectura sin mutaciones y exportadores existentes: regresión seleccionada satisfactoria. Pint y Larastan pasan. Fuentes previas, índice Git y evidencias históricas se conservan con las excepciones autorizadas y preexistentes documentadas. La aceptación definitiva pertenece al responsable.

## 21. Recomendaciones

Revisar Reportes con una cuenta autorizada y completar evaluación con lector de pantalla. Propuesta siguiente: UI-08A, presentación de catálogo, mesas y resumen del POS, manteniendo acciones, cantidades, notas, atajos y estados. El cobro y las correcciones requieren su revisión visual específica posterior; no iniciar ninguno sin autorización.

## 22. Propuesta de estado final

**Pendiente de aceptación**, con las limitaciones documentadas. UI-07D termina aquí; UI-08A no se inicia automáticamente.
