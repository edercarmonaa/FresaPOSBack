# Evidencia local — Checkout 1.5d.3

17 de noviembre de 2026. Chrome real en modo headless/CDP, PHP/Livewire y SQL Server Express. Servidor QA 127.0.0.1:8083, perfil, cookies, archivos y usuarios ficticios separados. Únicamente fresapos_test; conexión operativa excluida. Procesos QA cerrados y fixtures retiradas con salvaguardas: cero tablas restantes.

**32 capturas**. Matrices open, cash-change, cash-receipt y mixed a 1440×900, 1024×768, 768×1024 y 390×844; sesión cerrada en las mismas cuatro resoluciones. Capturas adicionales de teclado, tarjeta, transferencia, confirmación mixta, mesa libre, respuesta perdida/recuperación, validación, carga, saldo Cash, mesero sin control y permiso retirado.

- [Cambio en móvil](cash-change-390.png), [pago mixto en tableta](mixed-768.png), [confirmación en escritorio](cash-receipt-1440.png): inspeccionadas visualmente.
- [Reporte Chrome](visual-report.json): cero errores JavaScript, desbordamientos horizontales o botones visibles menores de 44 px en las 32 mediciones. No sustituye una auditoría completa de accesibilidad.
- [Carga real bajo latencia](loading.json): una petición pendiente y dos controles financieros deshabilitados.
- [SQL del fixture](sql-evidence.json): cinco ventas cerradas, seis pagos, cinco auditorías, folios 202611170001–202611170005, mesa libre y cero movimientos manuales.
- Fondo 100.0000 + efectivo neto confirmado 440.0000 = **540.0000**. Tarjeta/transferencia no aumentaron efectivo; el cambio no se sumó otra vez.
- [OpenAPI final](checkout-1.5d3-openapi-final.json): 52 operaciones y 281 respuestas reales, cero errores/advertencias.
- [Integridad](checkout-1.5d3-integrity-report.json): 23 tablas previas y diez archivos protegidos intactos respecto al despliegue; dos ventas y tres líneas conservadas, cero cobros operativos de prueba.
- [Actividad operativa conservada](checkout-1.5d3-operative-activity.json): diferencias observadas mientras trabajaban los fixtures; no se restauraron filas anteriores.
- [HTTP operativo](checkout-1.5d3-health.json): disponibilidad y acceso protegido después de aplicar la migración.
- Doce archivos checkout-1.5d3-race-*.json: procesos HTTP/SPID independientes, incluyendo cantidades concurrentes, folios diarios, mezcla, rechazo por sesión, rollback, respuesta perdida y deadlock real 1205 recuperado.
- Doble clic físico dejó un cierre. Chrome descartó una respuesta después del HTTP 200 del servidor; al recargar se recuperó el folio original sin duplicar pagos. No se afirma un corte físico de red.
- Se retiró el permiso del cajero de QA con la pantalla ya preparada y el intento firmado fue rechazado. La transición Cash closed ocurrió solo en SQL de fixture; no existe operación productiva de cierre de caja.

El motor computer-use Windows no inició en dos intentos; se completó la revisión con Chrome. El runtime Python ya no disponía del módulo websocket usado por helpers anteriores; el transporte local CDP se implementó con la biblioteca estándar, sin instalar dependencias. Los perfiles/scripts privados permanecen ignorados en .tools. No se publican credenciales operativas, tokens, respaldos o rutas privadas del servidor.
