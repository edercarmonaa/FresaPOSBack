# Evidencia de la etapa 1.6b

Todas las operaciones de QA se ejecutaron en `fresapos_test`, con servidor, sesiones y archivos aislados. Las capturas contienen datos ficticios; no se publican cookies, tokens, contraseñas ni filas operativas.

- `visual-report.json`: Chrome real, cuatro resoluciones, 129 capturas; 85 vistas generales, 40 de detalle dentro del panel desplazable y cuatro del cajero con venta seleccionada. Sin desbordamiento horizontal ni errores de página observados.
- `detail-*.png`: controles de porcentaje, fijo, sustitución, eliminación, validación y cierre cero. Los 85 PNG generales permiten revisar ticket, roles, cambio PIN, doble clic y recuperación de respuesta perdida.
- `visual-sql-evidence.json`: cinco ventas ficticias, tres cierres cero con folio y sin pagos; un cierre positivo con un pago, ningún movimiento manual ni intención pendiente.
- `cashier-selected-*.png` y `selected-role-sql-evidence.json`: comprobación adicional con un cajero y una venta de 180.0000 seleccionada, sin componente de descuentos, pagos ni movimientos.
- `discounts-race-*.json`: carreras de conexiones SQL independientes, bloqueos reales, respuesta perdida, rollback, clave compartida, revocación y deadlock 1205. PIN usa el cambio real de empleado y rechaza la solicitud anterior después de liberar el bloqueo.
- `test-results.json`: línea base, regresión, inventario nominal de las 641 pruebas previas y calidad.
- `openapi-report.json`: contrato formal y muestras HTTP reales de las 64 operaciones.
- `deployment-evidence.json`, `operative-invariants-final.json` y `test-database-final.json`: respaldo verificado, migración local aplicada, conservación de datos, 21 controles sin violaciones y cero tablas en la base de pruebas al finalizar.
- `health-final.json`: trece comprobaciones HTTP correctas del servidor principal 8080.

**ETAPA 1.6b: ACEPTADA LOCALMENTE**. Consultar el [informe A–T](../../entrega-1.6b.md) para los resultados definitivos, los fallos corregidos y los pendientes externos.
