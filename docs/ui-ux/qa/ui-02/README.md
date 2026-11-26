# QA — UI-02 Design System

26/11/2026. Alcance: nuevos recursos visuales y componentes reutilizables, catálogo separado, sin integración en pantallas funcionales.

## Línea base y regresión final

| Verificación | Antes | Después |
|---|---|---|
| PHPUnit Feature,Architecture,Modules | 261 pruebas / 1056 aserciones | 267 pruebas / 1085 aserciones |
| Errores / fallos / omitidas | 0 / 0 / 0 | 0 / 0 / 0 |
| Tiempo de consola / memoria | 00:27.929 / 84 MB | 00:17.075 / 84 MB |
| Pint --test | Salida 0 | Salida 0 |
| Larastan nivel 5 existente | Salida 0 | Salida 0 |
| Composer validate --strict | Salida 0 | Salida 0 |
| Composer check-platform-reqs | Salida 0 | Salida 0 |

El aumento es de seis pruebas nuevas y 29 aserciones en DesignSystemRenderingTest. Comprueban contratos de renderizado: `wire:*`, tipo de botón explícito/predeterminado, disabled, IDs/nombres/asociaciones, escape de textos, variantes desconocidas, caption/región y ausencia de carga del nuevo sistema en el inicio existente. No se modificó ni debilitó ninguna prueba previa. Una ejecución focal previa también pasó: 6/29, 00:02.164.

Comandos reales, PowerShell desde la raíz; cambiar baseline por final al repetir:

```powershell
.\scripts\local.cmd composer test -- --testsuite Feature,Architecture,Modules --log-junit .tools/ui02-final.xml
.\scripts\local.cmd composer lint
.\scripts\local.cmd composer analyse
.\scripts\local.cmd composer validate --strict
.\scripts\local.cmd composer check-platform-reqs
```

[Resumen y salidas](quality.json), [JUnit inicial](baseline.xml), [JUnit final](final.xml), [consola inicial](baseline-test.log), [consola final](final-test.log). Los demás logs baseline/final se conservan en esta carpeta. La variación temporal no se presenta como mejora de rendimiento: fueron ejecuciones distintas, sin ensayo controlado.

No se repitió la suite completa de integración SQL: ningún modelo, servicio, migración, ruta, configuración, contrato o flujo financiero cambia, ni las pantallas actuales cargan los activos nuevos. Se ejecutó la misma selección que la línea base, más los seis contratos de componentes. La ejecución completa de UI-00 (906/11653) permanece como antecedente histórico, no como resultado de UI-02. No se consultaron ni compararon hashes de filas de fresapos.

## Navegador y evidencias

Chrome 158.0.8127.37 y Edge 158.0.4370.33 instalados; Playwright 1.62.1 ya disponible. Modo headless, escala 1, sin limitación de CPU/red. No hubo instalación de herramientas. Se continuó con CLI existente tras los fallos de inicialización CUA/nativa documentados en UI-00; no se reintentó el helper fallido.

Chrome: siete tamaños × dos temas = 14 capturas. Edge: 1366×768 y 390×844 × dos temas = 4. Tamaños Chrome: 2560×1440, 1920×1080, 1366×768, 1024×768, 768×1024, 390×844, 320×844. Captura adicional de texto raíz aumentado de 14 a 28 px a 320 px: **19 PNG finales**. El aumento del texto raíz es una prueba parcial de reflujo, no un ensayo de zoom real al 200/400 %.

[Resultados](../../evidencias/ui-02/browser-results.json), [matriz responsive](responsive.csv), [trazabilidad](traceability.csv), [salida](browser-run.log), [runner](browser-check.cjs). Resultado final: 18 combinaciones, 12 comprobaciones de interacción y 40 contrastes; cero infracciones detectadas, errores de página/consola o solicitudes fuera del origen GET documental.

Geometría: sin overflow global, H1 único, IDs únicos, controles activos con nombre y tamaño mínimo medido de 44×44 px, fuente Inter cargada. Las tablas mantienen scroll interno. Se excluyen controles deshabilitados y enlaces en línea del cálculo táctil. Contraste: texto/surface, muted/surface y soft, acción, selección, éxito, advertencia, error (≥4.5:1), borde y foco (≥3:1), ambos temas y navegadores. No se afirma cobertura exhaustiva de combinaciones, fondos o estados posibles.

Interacciones: automático según SO, prioridad de selección explícita, persistencia al recargar, almacenamiento exclusivo de theme, densidad de filas, movimiento reducido, foco visible, botón de muestra sin operación, reflujo de texto aumentado, JSON inválido, claves/valores ajenos y storage bloqueado. El catálogo solo usa ejemplos declarados; no admite credenciales ni envía operaciones.

Reproducir con servidor 8092 activo y herramienta existente, sin instalación:

```powershell
& 'C:/Users/Usuario/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/bin/node.exe' docs/ui-ux/qa/ui-02/browser-check.cjs
```

El runner escribe la carpeta de evidencias UI-02. Preservar resultados aceptados antes de repetir. Las rutas de los navegadores y Node corresponden a este equipo; no son requisitos del hosting.

## Incidencias, revisión visual y comparación

El [primer reporte](attempt-01.json) tuvo un fallo: una cadena tipográfica indivisible desbordaba al ampliar el texto raíz a 28 px a 320 px. Se conserva la [captura negativa](attempt-01-text-200-320.png). Se separaron los grupos numéricos en spans indivisibles con oportunidad de salto entre ellos; la repetición pasó. La revisión visual también detectó expansión desigual de campos con/sin ayuda: se añadió align-content:start y se actualizó la matriz final, sin fallos.

Se inspeccionaron directamente las capturas de escritorio claro, móvil oscuro y texto aumentado, y se revisó nuevamente el área de campos final. La comparación con los componentes de UI-01 conserva identidad, paleta, tipografía y estados aprobados; la distribución ahora es un catálogo de componentes reales, no el layout de aplicación propuesto. No se sustituyeron baselines anteriores ni se afirma inspección manual de cada PNG. Las pantallas existentes conservan fuentes y no cargan el sistema nuevo: no se atribuye a ellas un cambio visual.

## Integridad y límites

[Integridad](source-integrity.json): 2707 archivos previos comprobados, únicamente docs/ui-ux/ESTADO.md actualizado; fuentes y pruebas existentes intactas. Diez protegidos idénticos. Manifiestos históricos: 48 entradas UI-00 y 117 UI-01 idénticas. Sin staged, commits, push, rama nueva, despliegue, dependencias o configuración modificada. [Inventario de recursos](asset-inventory.json): CSS+JS productivos 10217 bytes sin comprimir, Inter 352240 bytes original idéntico a UI-01, licencia/procedencia incluidas. No son métricas de rendimiento de usuario.

No ejecutados: Firefox/Safari (motores no disponibles), lector de pantalla/dispositivo físico, axe/Lighthouse (no incorporados), zoom real integral, LCP/INP/CLS, E2E financiero con apariencia nueva (sin integración), SQL completo, impresión/PDF/XLSX o Azure/cPanel. WCAG 2.2 AA continúa como objetivo, sin declaración de conformidad completa.

El [manifiesto](manifest.json) sella los artefactos nuevos de esta etapa, incluidos recursos productivos y componentes, con rutas relativas al repositorio. El índice vivo ESTADO.md se excluye. La aceptación sigue a cargo del responsable.
