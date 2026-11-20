# Evidencia local — Etapa 1.6c

Chrome 157 real, servidor QA aislado y datos ficticios exclusivamente en `fresapos_test`. Resoluciones: 1440×900, 1024×768, 768×1024 y 390×844. No se utilizaron ventas operativas. El navegador y el servidor QA se cerraron; las tablas de pruebas se retiraron antes de iniciar la regresión final.

## Revisión visual

Las 133 capturas y sus mediciones se conservan junto a este documento. `visual-summary.json` resume los resultados: cero desbordamientos horizontales, cero errores de página y cero botones financieros menores de 44 px. `visual-report.json` contiene el detalle por captura.

La secuencia incluye hallazgos intermedios, para conservar evidencia del trabajo realizado:

- `06-*`: contraste del botón detectado antes del ajuste; las capturas posteriores muestran fondo azul oscuro y texto blanco.
- `31-*`: revisión detallada del cobro adicional; en móvil se detectó el salto del último decimal. `33-money-layout-fixed-*` demuestra la corrección.
- `32-live-receipt-refreshed-*`: recibo actualizado automáticamente a 540.0000 y tres pagos tras el segundo cobro adicional, conservando el folio.
- `34-uncollected-draft-discarded-*`: el borrador usado para revisar el ajuste visual se descartó expresamente, sin cobrarlo.

También se comprobaron observación, notas, cantidades, productos, motivos, descuentos porcentuales/fijos, retiro de descuento, comparación original/propuesta, doble clic, respuesta HTTP perdida tras commit, recuperación, bloqueo de devolución, finalización sin cambios, mesa reutilizada, bloqueo de arqueo, navegación por teclado, cambio por PIN, lectura sin autorización especial y sesión revocada.

## Conciliación de los datos ficticios

`sql-evidence.json`: seis ventas pagadas y una venta de mesa abierta; los seis pagos originales permanecieron idénticos y hubo dos pagos adicionales. Se conservaron folios y primeros cierres, sin consumir consecutivos adicionales. Saldo esperado: 1540.0000. Seis correcciones efectivas, dos descartadas y una sin cambios; seis eventos funcionales. Cero movimientos manuales, conciliaciones, operaciones Core pendientes o ventanas SQL sin finalizar. Las 28 comprobaciones financieras no encontraron violaciones.

## Concurrencia y calidad

Los dieciséis archivos `corrections-race-*.json` proceden de procesos HTTP y conexiones SQL independientes, incluyendo esperas reales de bloqueo y un deadlock SQL 1205 recuperado. `corrections-inventory.json` acredita las 705 pruebas anteriores conservadas y 64 nuevas. `corrections-final-quality.json` registra los códigos de salida de Pint, Larastan, Composer y plataforma.

## Cierre verificado

- [Regresión y comparación con línea base](results.json): 769 pruebas / 9216 aserciones, 22:09.285, 220.00 MB, exit 0.
- [OpenAPI](corrections-openapi-final.json): 70 operaciones / 518 respuestas reales, sin errores ni advertencias.
- [Despliegue](corrections-deployment-evidence.json): respaldo COPY_ONLY/CHECKSUM verificado, migración 17 aplicada, 28 tablas; datos anteriores y diez archivos protegidos conservados.
- [Integridad](corrections-operative-invariants-final.json): 28 comprobaciones sin violaciones, 64 columnas ULID inspeccionadas.
- [HTTP](corrections-health.json): 13 comprobaciones satisfactorias en el servidor original 8080.
- [Limpieza](corrections-test-database-final.json): cero tablas en la base independiente al finalizar.

El [informe de entrega A–W](../../entrega-1.6c.md) documenta comandos, fallos intermedios corregidos y límites externos. No se incluyen tokens, cookies, perfiles de navegador, credenciales, exportaciones de respuestas autenticadas ni rutas físicas del respaldo. No hubo commits, pushes ni despliegues externos.
