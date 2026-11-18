# Evidencia de la etapa 1.6a

Todas las escrituras de aceptación se ejecutan en `fresapos_test`. Ninguna captura contiene ventas reales, cookies, tokens ni contraseñas.

- `visual-report.json` y 85 PNG: Chrome 157, cuatro resoluciones, cero desbordamientos y errores de página.
- `visual-sql-evidence.json`: retiro y cancelaciones únicos, pago conservado, ningún movimiento manual ni intención pendiente.
- `reasons-race-*.json`: doce carreras SQL/HTTP independientes con bloqueos reales, respuesta perdida, rollback y deadlock 1205 recuperado.
- `test-results.json`: línea base y regresión definitiva, comandos y resultados reales.
- `openapi-report.json`: validación formal y respuestas HTTP reales.
- `deployment-evidence.json`: respaldo verificado, migración incremental, filas originales y diez archivos protegidos.
- `operative-invariants-final.json`: invariantes financieras, solo lectura.
- `test-database-final.json`: desmontaje de los fixtures.

El [informe A–R](../../entrega-1.6a.md) distingue resultados definitivos, fallos corregidos y pendientes externos. Los archivos de cierre se incorporan al finalizar sus comprobaciones.
