# QA de UI-01

26/11/2026. Resultado del prototipo separado; no es una prueba de la aplicación integrada con el rediseño.

## Ejecución y resultados

| Verificación ejecutada | Resultado real |
|---|---|
| PHPUnit Feature,Architecture,Modules | 261 pruebas, 1056 aserciones, 0 errores/fallos/omitidas; 00:29.926, 84 MB; salida 0 |
| Composer lint (Pint --test) | Salida 0 |
| Composer analyse (Larastan/PHPStan, nivel 5 existente) | Salida 0, 426 archivos analizados |
| Composer validate --strict | Salida 0 |
| Composer check-platform-reqs | Salida 0 |
| Navegador, ejecución final | 90 combinaciones, 21 comprobaciones de interacción, 40 contrastes; 0 infracciones detectadas |
| Consola / errores de página / peticiones ajenas o distintas de GET | 0 / 0 / 0 |
| SHA de archivos existentes | 2588 idénticos; 10 protegidos idénticos; 48 entradas del manifiesto UI-00 idénticas |
| Laravel existente en 8080 | GET `/up` y `/api/v1/ping`: HTTP 200; [registro](application-health.json) |

Comandos ejecutados desde PowerShell en la raíz:

```powershell
.\scripts\local.cmd composer test -- --testsuite Feature,Architecture,Modules --log-junit .tools/ui01-regression.xml
.\scripts\local.cmd composer lint
.\scripts\local.cmd composer analyse
.\scripts\local.cmd composer validate --strict
.\scripts\local.cmd composer check-platform-reqs
```

Resultados crudos: [JUnit](regression.xml), [consola PHPUnit](regression.log), [resumen](regression-summary.json), [calidad](quality.json), [Pint](lint.log), [Larastan](analyse.log), [Composer](validate.log), [plataforma](check-platform-reqs.log), [integridad](source-integrity.json).

La suite completa de UI-00 tuvo 906 pruebas / 11653 aserciones / 35:27.808 / salida 0, según [registro histórico](../../03-linea-base-funcional.md). **No se ejecutó de nuevo esa suite SQL en UI-01**. El alcance actual solo crea documentación y prototipos estáticos, sin modificaciones de código, pruebas, dependencias, configuración ni consultas de negocio. La comparación de hashes y la regresión seleccionada sostienen esa delimitación; no se presentan 906 pruebas como un resultado nuevo.

## Navegadores y cobertura

Se reutilizó Playwright 1.62.1 existente con Chrome 158.0.8127.37 y Edge 158.0.4370.33 instalados. Ambos en modo headless, escala 1, sin limitación de red/CPU. No se instalaron navegadores ni paquetes. Las herramientas CUA/nativa habían fallado durante UI-00; el CLI ya disponible permitió revisar el prototipo y obtener capturas.

Chrome: cinco vistas, siete tamaños, dos temas (70 capturas). Edge: cinco vistas, dos tamaños, dos temas (20). Captura adicional del resumen móvil con texto ampliado a 320 px (91 PNG en total). [Resultados completos](../../evidencias/ui-01/browser-results.json), [matriz CSV](traceability.csv), [salida final](browser-run.log).

Se comprobó: ausencia de overflow global, un H1 visible, IDs únicos, nombres de controles, carga de Inter, mínimos de 44×44 px para controles activos medidos; 10 pares de contraste por tema y navegador. Enlaces de texto en línea y controles deshabilitados se excluyen de la medición táctil. No se mide toda combinación de texto/fondo ni todo estado de foco de la aplicación.

Interacciones: automático sigue cambios de tema del sistema, selección explícita prevalece, reducción de movimiento del SO, menú colapsado persistente, preferencias/densidad/texto, Tab contenido en diálogos, Escape y devolución del foco, resumen móvil, confirmación crítica deshabilitada, almacenamiento de cinco preferencias visuales, JSON/valores inválidos, bloqueo de storage, pestañas por flechas, acceso de solo lectura y formulario sin envío. Se restringieron las solicitudes a GET del origen estático 8091.

Runner reproducible: [browser-check.cjs](browser-check.cjs). Con el servidor 8091 activo y la herramienta **ya existente** en `.tools/node_modules/playwright`, ejecutar desde la raíz (sin instalar dependencias):

```powershell
& 'C:/Users/Usuario/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/bin/node.exe' docs/ui-ux/qa/ui-01/browser-check.cjs
```

El runner escribe capturas y resultados en la carpeta UI-01. Conservar una copia antes de repetir para no sustituir evidencia aceptada. Las rutas de ejecutables son las observadas en este equipo; no son dependencias del prototipo ni del hosting.

## Incidencias y revisión visual

El [primer intento](attempt-01.json) registró cuatro fallos: dos lecturas inmediatas anteriores a eventos `matchMedia`, y dos recorridos de Tab que alcanzaban el documento al envolver el diálogo nativo. Se corrigieron las esperas de estado en el runner y se añadió contención explícita de Tab en el prototipo. No se relajaron los resultados esperados. La repetición pasó. Tras revisión visual se ajustó la disposición de nombre/importe del resumen a 320 px y se repitió la matriz final; también pasó. El primer reporte negativo permanece disponible.

Revisión visual directa de muestras: Reportes claro 1366, POS oscuro 1366, Productos oscuro 390, Acceso claro 1366, Componentes oscuro 390 y resumen móvil oscuro con texto ampliado 320. El resto dispone de capturas y comprobación geométrica, sin afirmar inspección manual individual de los 91 PNG.

## Límites

No ejecutados: Firefox/Safari (sin motores disponibles), lector de pantalla, axe/Lighthouse (no incorporados), zoom real integral, dispositivo táctil físico, impresión/PDF/XLSX, Azure/cPanel ni E2E autenticado con apariencia nueva. Las últimas pantallas aún no existen en Laravel: son una propuesta. No se midieron LCP/INP/CLS actuales ni se afirma una mejora cuantitativa de rendimiento. Se sirve una fuente local de 352240 bytes, sin CDN ni llamadas API en el prototipo.

No se calcularon hashes de filas de `fresapos`; no se afirma una comparación de datos. No se abrieron ventas, sesiones de caja ni operaciones financieras mediante el navegador. La validación completa de las pantallas protegidas y sus permisos corresponde a etapas de integración autorizadas y QA aislada.

El [manifiesto](manifest.json) sella únicamente los nuevos artefactos de UI-01. `ESTADO.md` es un índice vivo y se excluye expresamente. UI-00 mantiene su manifiesto original intacto.
