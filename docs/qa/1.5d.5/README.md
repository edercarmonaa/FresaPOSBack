# Evidencia local — 1.5d.5

Todos los escenarios que escriben usan exclusivamente `fresapos_test`. La base operativa se inspecciona con SELECT y huellas, sin preparar datos de demostración. [Informe completo](../../entrega-1.5d.5.md).

## Recorrido Web

Chrome 157.0.8103.79 real, perfil aislado, servidor QA 8084. **76 capturas únicas**, cuatro resoluciones: 1440×900, 1024×768, 768×1024 y 390×844. [Reporte de medidas](visual-report.json), [evidencia SQL posterior](sql-evidence.json), [estado de carga](loading.json).

Se recorrieron login, catálogo, apertura, mostrador, productos/notas, pausa/recuperación, efectivo insuficiente y cálculo de cambio, cobros de los cuatro métodos, mesa/pago mixto, respuesta HTTP 200 perdida y recuperación por recarga, movimientos, formulario ciego, cierre, historial y nuevo turno con fondo cero. Doble clic en apertura, cobros y movimientos; latencia en cierre; foco con Tab. No se usa un endpoint oculto para confirmar operaciones del recorrido Web.

Resultado persistido: dos sesiones/cierres/arqueos, cinco ventas cerradas, seis pagos confirmados, dos movimientos y cero intenciones pendientes. Primer turno: 100 + 30 − 10 + 260 efectivo neto = **380.0000**; total vendido **900.0000**. Tarjeta 280, transferencia 180 y otro 180 no aumentan el efectivo. Segundo turno vacío y arqueo cero, sin arrastre. Un rechazo por efectivo insuficiente queda como recibo failed; 21 recibos completed, sin efectos parciales.

Sin desbordamiento horizontal ni excepciones JavaScript en las 76 capturas. Controles financieros visibles de al menos 44 px. **Observación preservada:** ocho capturas Login/Catalog contienen controles no financieros menores de 44 px; el botón Entrar mide 40 px. La sonda heredada de Cash inicialmente lo trató como fallo global. Se mantiene el hallazgo y la comprobación financiera de 44 px, sin modificar pantallas estables únicamente por tamaño. La revisión no certifica accesibilidad completa con lector de pantalla.

El registro CDP contiene **un error de red esperado `ERR_CONNECTION_CLOSED`** al descartar deliberadamente la respuesta de checkout, cero excepciones de ejecución y cero errores de consola inesperados. No se oculta ese evento como si la red no hubiera fallado: [detalle seguro](browser-errors.json).

Selección de capturas:

| Escenario | Escritorio | Móvil |
| --- | --- | --- |
| Mostrador con línea | [1440](counter-line-1440.png) | [390](counter-line-390.png) |
| Efectivo/cambio | [1024](cash-change-1024.png) | [390](cash-change-390.png) |
| Mesa ocupada | [1440](table-occupied-1440.png) | [390](table-occupied-390.png) |
| Mixto recuperado | [1440](mixed-recovered-1440.png) | [390](mixed-recovered-390.png) |
| Arqueo ciego | [1440](blind-count-1440.png) | [390](blind-count-390.png) |
| Cierre cuadrado | [768](closing-receipt-768.png) | [390](closing-receipt-390.png) |
| Segundo turno | [1440](second-turn-1440.png) | [390](second-turn-390.png) |

Las capturas muestran únicamente usuarios y datos ficticios. No se publican contraseñas, tokens, muestras de login, archivos de sesión o huellas de filas operativas. Los procesos QA se detienen y sus tablas se retiran antes de la suite final.

## API, SQL y entorno

- [OpenAPI](openapi-report.json): 57 operaciones reales; 388 respuestas HTTP, cobertura de muestras en las 57, cero errores/advertencias. Datos completos de respuestas solo en `.tools/acceptance-all-responses.json`.
- [Integridad del ciclo API](cycle-integrity.json): misma sonda SQL compartida, sin violaciones.
- [Invariantes operativas iniciales](operative-invariants-before.json): solo lectura; ninguna reparación automática.
- [Disponibilidad](health-report.json): nueve comprobaciones reales de 8080; `/up`, ping, login, Livewire y barreras anónimas.
- [Tareas Windows](windows-scheduler.json): consulta de solo lectura, cero coincidencias del proyecto. No se crean tareas. El registro horario de Laravel y la lógica de compactación se prueban por separado.

## Regresión e integridad final

- [Resultados](test-results.json): **593 pruebas / 6832 aserciones**, 14:08.380, 166 MB, exit 0; baseline 589/6426. Pint, Larastan nivel 5 (349 archivos), Composer estricto y plataforma correctos.
- [Huellas](integrity-report.json): 26 tablas y diez archivos protegidos idénticos; conserva los dos arqueos operativos previos y la sesión abierta.
- [Invariantes finales](operative-invariants-final.json): 21 comprobaciones sin violaciones, solo lectura.
- [Base de pruebas final](test-database-final.json): cero tablas de fixtures.
- [Rendimiento](query-measurements.json): seis consultas para catálogo/mesas/ventas tanto con páginas de 10 como de 25; medidas de escrituras/consultas financieras, sin atribuirles un SLA.

**33 reportes de carreras financieras actuales**, producidos por la suite final: `checkout-1.5d3-race-*.json` (12), `finance-1.5d2b-race-*.json` (9), `reconciliation-race-*.json` (12). Conservan los prefijos emitidos por las pruebas originales; sus contenidos son de esta ejecución. Deadlock SQL real: [Checkout](checkout-1.5d3-race-deadlock.json) y [cierre Cash](reconciliation-race-deadlock.json), ambos 1205. [Reconexión tras commit](finance-1.5d2b-race-connection_after_commit.json) sobre el SPID propio del worker de pruebas. La suite también conserva las otras pruebas concurrentes previas de Core, apertura y Sales.

**ETAPA 1.5d.5: ACEPTADA LOCALMENTE**. Azure SQL/cPanel y activación de tareas del sistema no se consideran verificados mediante estas evidencias locales. Sin commits, pushes o despliegues.
