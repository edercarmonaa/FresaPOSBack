# QA — UI-03 Layouts y navegación

26/11/2026. Alcance: integración del marco visual y navegación; los módulos conservan sus formularios y lógica. Estado: pendiente de aceptación. Suite completa aprobada, con intentos previos conservados.

## Línea base y regresión seleccionada

| Comprobación | Antes | Después |
|---|---|---|
| PHPUnit Feature,Architecture,Modules | 267 pruebas / 1085 aserciones | 282 pruebas / 1144 aserciones |
| Errores / fallos / omitidas | 0 / 0 / 0 | 0 / 0 / 0 |
| Tiempo de consola / memoria | 00:20.014 / 84 MB | 00:18.695 / 88 MB |
| Pint --test | Salida 0 | Salida 0 |
| Larastan nivel 5 | Salida 0 | Salida 0 |
| Composer validate --strict | Salida 0 | Salida 0 |
| Composer check-platform-reqs | Salida 0 | Salida 0 |

[Resultados](quality.json), [JUnit inicial](baseline.xml), [JUnit seleccionado final](final.xml), [consola inicial](baseline-test.log), [consola final](final-test.log). Las diferencias temporales no prueban una mejora de rendimiento.

Se agregaron 15 pruebas / 55 aserciones: doce perfiles comparados en tres contextos (36 comparaciones), navegación anónima, contrato del login y estructura compartida. Las únicas cuatro aserciones adicionales restantes corresponden a la [excepción autorizada](authorization.md) sobre la última prueba de DesignSystemRenderingTest. Las otras cinco pruebas de ese archivo y las demás pruebas anteriores se conservan. El verificador detectó una conversión accidental de «Información» a mojibake al preparar el archivo; se restauró desde HEAD y se repitió la clase: 6 pruebas / 33 aserciones, salida 0. La comparación exacta del archivo valida que solo subsiste la excepción autorizada.

Comandos PowerShell desde raíz:

```powershell
.\scripts\local.cmd composer test -- --testsuite Feature,Architecture,Modules --log-junit .tools/ui03-final.xml
.\scripts\local.cmd composer lint
.\scripts\local.cmd composer analyse
.\scripts\local.cmd composer validate --strict
.\scripts\local.cmd composer check-platform-reqs
```

## Suite completa e incidentes de ejecución

Se conserva cada intento, sin presentarlo como aprobado:

1. `.\scripts\local.cmd composer test -- --log-junit .tools/ui03-full.xml`: Composer interrumpió el proceso a los 300 segundos. Salida 1, sin JUnit completo válido. [Log](full-attempt-01.log), [resultado](full-attempt-01-result.json).
2. `.\scripts\local.cmd php vendor/phpunit/phpunit/phpunit --log-junit .tools/ui03-full-retry.xml`: 927 pruebas, 1202 aserciones, 633 errores de preparación y cero fallos/omitidas. El corte anterior había dejado el esquema temporal y la salvaguarda rechazó sobrescribir tablas de seguridad. [JUnit](full-attempt-02.xml), [resumen de mensajes](full-attempt-02-summary.json), [log](full-attempt-02.log), [resultado](full-attempt-02-result.json).
3. Recuperación: se verificó que no quedara PHPUnit activo, la conexión efectiva fuera exactamente fresapos_test y las 25 tablas correspondieran a la ventana de creación de nuestra ejecución interrumpida (11:03:47–11:03:49). Se retiraron únicamente esas tablas y sus FK dentro de una transacción, rechazando cualquier nombre, tabla adicional o fecha diferente. La base de pruebas quedó vacía. No se accedió a fresapos. [Metadatos y comprobación](test-recovery.json). No se cambió ningún test/migración para omitir el rechazo.
4. Ejecución limpia, sin timeout de Composer ni cambio permanente de configuración:

```powershell
.\scripts\local.cmd php -d memory_limit=1536M vendor/phpunit/phpunit/phpunit --log-junit .tools/ui03-full-final.xml
```

**Resultado limpio: 927 pruebas / 11741 aserciones, 29:39.993, 384.00 MB; cero errores/fallos/omitidas y salida 0.** Repetición seleccionada posterior al ajuste de compatibilidad: 282/1144, 00:26.677, 88 MB, salida 0; [JUnit](post-review.xml) y [consola](post-review.log).

El [resultado completo de cierre](full-results.json), [JUnit completo](full-final.xml) y [log completo](full-final.log) son la fuente de verdad; no se infiere su éxito de la selección previa. El límite de memoria se aplica solo al proceso, igualando el usado por el lanzador Composer existente. No se modificó php.ini, composer.json ni conexiones. Los datos de pruebas se limitan a SQL Server/fresapos_test con TestDatabaseGuard; no se calcularon hashes de filas operativas.

Un intento focal inicial con un filtro que contenía `|` no llegó a PHPUnit porque el wrapper Windows lo interpretó como separador. Se conservó el [incidente de comando](command-incident.log) y se repitió mediante rutas de archivo: componentes 6/33 y arranque 3/14, ambos salida 0.

## Navegador

Chrome 158.0.8127.37 y Edge 158.0.4370.33 instalados, Playwright 1.62.1 ya disponible, headless, escala 1, sin throttling. Se mantuvo el CLI existente tras los fallos de inicialización CUA/nativa de UI-00; sin instalaciones o reintentos de esos helpers.

Cinco vistas: inicio/login reales de 8080 y fixtures admin/POS/Reportes de 8093. Chrome: 7 tamaños × 2 temas × 5 vistas = 70 combinaciones. Edge: 2 tamaños × 2 temas × 5 vistas = 20. **90 combinaciones y 91 PNG**, incluyendo drawer con texto ampliado a 320 px. Tamaños Chrome: 2560×1440, 1920×1080, 1366×768, 1024×768, 768×1024, 390×844, 320×844; Edge: 1366×768 y 390×844.

[Resultados finales](../../evidencias/ui-03/browser-results.json), [responsive](responsive.csv), [trazabilidad](traceability.csv), [runner](browser-check.cjs), [salida](browser-run.log). Resultado: 20 comprobaciones de interacción y cero infracciones detectadas, errores de página/consola o solicitudes inesperadas. Se permitió GET de los dos orígenes locales y del CDN Tailwind ya existente; nunca POST ni operaciones de negocio.

Geometría: sin overflow global, H1 único, IDs únicos, nombres accesibles, Inter cargada y controles nuevos ≥44×44 px. Se comprueba además el tamaño del heading UI para detectar conflictos de capas y la altura de la franja POS en escritorio. La tabla responsive registra tamaños de controles antiguos sin ocultarlos; los enlaces de texto en línea y controles deshabilitados se excluyen del cálculo táctil.

Interacciones: tema automático/manual, persistencia, colapso, dialog nativo de preferencias, Tab/Escape/retorno de foco, movimiento reducido del SO, cinco preferencias exclusivas, texto ampliado a 320, drawer con semántica/inert, Tab contenido, resize, conservación del inert anterior, restablecer, JSON/valores/claves inválidos, storage bloqueado y contrato del login. La preferencia de densidad es visual y solo la consumen tablas UI; no cambia filtros, consultas o paginación.

Reproducir con 8080 y el servidor de fixtures activos, conservando una copia de evidencias aceptadas antes de repetir:

```powershell
& 'C:/Users/Usuario/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/bin/node.exe' docs/ui-ux/qa/ui-03/browser-check.cjs
```

Las fixtures se renderizan con el layout real y Gate/identidad en memoria, sin sesión autenticada o datos operativos. Sus tokens son marcadores. No equivalen a E2E financiero autenticado ni a revisión visual de todo el contenido de los módulos. El servidor solo sirve una lista cerrada y GET.

## Inspección visual y comparación

La primera [ejecución automática](attempt-01-browser.json) pasó, pero la revisión manual detectó dos problemas que la geometría inicial no medía: [franja POS demasiado alta](attempt-01-pos.png) y [componentes anulados por preflight](attempt-01-admin.png). Se corrigieron filas del grid/precedencia de capas y se añadieron comprobaciones para ambos casos. También se normalizó el margen del enlace Mi cuenta heredado, se aseguró la restauración de inert y la altura mínima de controles. Se agregó además un ámbito claro heredado para POS/Reportes, evitando texto claro sobre sus superficies blancas fijas sin rediseñar sus contenidos antes de etapa. La repetición final se registra en browser-results.json.

Revisión directa representativa: inicio claro escritorio, login oscuro móvil, administración clara escritorio, POS oscuro escritorio antes/después, drawer y preferencias móviles. No se afirma inspección individual manual de los 91 PNG. Antes histórico: [inicio UI-00](../../evidencias/ui-00/chrome-inicio-1366x768.png) y [login UI-00](../../evidencias/ui-00/chrome-login-1366x768.png), cuyos templates seguían intactos hasta UI-03. Después: capturas UI-03. La comparación confirma cambios de marco/tema autorizados; no es un ensayo de regresión píxel a píxel en condiciones idénticas.

## Accesibilidad y medición local

[Comprobaciones adicionales](additional-results.json), [runner](additional-checks.cjs): salto al contenido enfoca main, foco visible, margen de salto compatible con cabecera sticky; seis contrastes computados sobre navegación, enlace activo y marca, en ambos temas. Todos ≥4.5:1 (mínimo observado 8.37:1). Además, [dos comprobaciones de compatibilidad](compatibility-results.json) y [su runner](compatibility-check.cjs) validan siete pares con el CSS real de POS/Reportes inyectado solo en fixtures: marco oscuro y contenido heredado claro. No es E2E de negocio ni auditoría de cada combinación posible de los módulos.

Login real, Chrome headless 1366×768, escala 1, sesión warm n=5, sin throttling, suite SQL activa en el mismo equipo: LCP observado mín/med/máx **424/456/756 ms**, CLS observado **0** en cinco muestras. Se conservan muestras y DCL. No hay INP real ni Lighthouse. No se infiere mejora frente a UI-00: condiciones y contenido no son idénticos. Tampoco se extrapola a cPanel/Azure o equipos físicos.

WCAG 2.2 AA es objetivo, no conformidad global certificada. No ejecutados: Firefox/Safari (sin motores disponibles), lector de pantalla, touch físico, axe/Lighthouse (no incorporados), zoom real integral y navegación visual de cada flujo financiero autenticado. UI-04/UI-07/UI-08/UI-09 tienen alcances posteriores que no se declaran hechos.

## Integridad

[Verificación](source-integrity.json): 2778 archivos previos comprobados, diez cambios permitidos, diez protegidos idénticos; las cinco secuencias de directivas de acceso se conservan. Solo cambia la prueba autorizada; todas las demás permanecen intactas. Informes, capturas y manifiestos UI-00/UI-01/UI-02 no se alteran. Los recursos productivos sí evolucionan bajo UI-03; se conservan [snapshots de fuente](source-snapshot) y nuevos hashes. Los manifiestos previos no se reescriben para ocultar esa evolución.

Sin dependencias, cambios de rama, staged, commits, push o despliegues. No se modifica código de negocio, migraciones, consultas productivas, autenticación, roles, permisos, conexiones, tickets o exportaciones. El [manifiesto UI-03](manifest.json) usa rutas relativas al repositorio y excluye el índice vivo ESTADO.md.
