# Evidencia local — 1.5d.4

Revisión del 18 de noviembre de 2026 con Chrome **157.0.8103.79** real, por CDP, perfil privado y servidor PHP independiente en 8084. Datos **exclusivos de fresapos_test**; nunca se cerraron sesiones operativas. Se consultó computer-use; su herramienta no está disponible en esta sesión y se reutilizó la alternativa Chrome instalada. Sin dependencias nuevas.

[Informe principal](../../entrega-1.5d.4.md) · [Registro de capturas](visual-report.json) · [Comprobación SQL](sql-evidence.json) · [Carga](loading.json) · [OpenAPI](openapi-report.json).

**38 capturas únicas**: escritorio 1440×900, tablet horizontal 1024×768, vertical 768×1024 y móvil 390×844. Se verificaron ancho del documento sin desbordamiento, ausencia de errores JavaScript y botones visibles de al menos 44 px. Se inspeccionaron también imágenes de escritorio, móvil y tablet; el formulario no muestra esperado/diferencia antes del conteo.

| Escenario | Evidencia |
| --- | --- |
| Sesión abierta | open-{1440,1024,768,390}.png |
| Tres pendientes: mostrador abierta/pausada y mesa | pending-sales-{1440,1024,768,390}.png |
| Formulario ciego sin panel financiero ni saldo en snapshot | blind-count-{1440,1024,768,390}.png |
| Navegación Tab entre importe y observación | keyboard.png |
| Latencia real, solicitud pendiente y controles disabled | loading.png / loading.json |
| Caja cuadrada 300−300=0, doble clic | balanced-{1440,1024,768,390}.png |
| Faltante 98−100=−2 | shortage-{1440,1024,768,390}.png |
| Sobrante 5−0=5 | surplus-{1440,1024,768,390}.png |
| HTTP 200 descartado por Chrome después del commit y recuperación | lost-response.png / recovered-{1440,1024,768,390}.png |
| Historial y filtros con/sin coincidencias | history-{1440,1024,768,390}.png |
| Permiso vigente al preparar y retirado antes de confirmar | permission-ready.png / permission-rejected.png |
| Mesero sin acceso Cash | waiter-forbidden.png |

La primera sesión del fixture conservó tres ventas cobradas: efectivo, tarjeta y transferencia de 180 cada una. Con fondo 100, entrada 30 y salida 10, el saldo esperado fue **300**, total vendido **540** y efectivo neto de ventas **180**. Los cierres posteriores usaron apertura explícita/fondo propio; no se trasladó el final anterior. Cinco arqueos, cinco auditorías de arqueo, cinco de cierre, dos diferencias, tres pagos confirmados, dos movimientos manuales y cero recibos pending. Una sexta sesión quedó abierta al rechazarse el cierre por permiso retirado.

Incidente del helper de QA: inicialmente se intentó retirar el permiso con el modelo Spatie estándar, que supone IDs enteros. SQL rechazó la conversión frente a los ULID reales; la revocación no ocurrió y el siguiente cierre de fixture fue legítimamente autorizado. Se corrigió **solo el helper aislado**, se abrió otra sesión ficticia y se repitió: la revocación real rechazó el cierre y mantuvo esa sesión abierta. La captura permission-ready se repitió y el reporte publicado contiene una sola entrada por archivo, **38**, no cuenta ambas ejecuciones como imágenes distintas. No se alteró la implementación de Users.

La pérdida de respuesta se produjo mediante Chrome Fetch después de HTTP 200; no se afirma haber cortado físicamente la conexión SQL. Los doce archivos reconciliation-race-*.json corresponden a pruebas automatizadas con procesos/SPID independientes y verifican espera SQL efectiva, cierres únicos, rollback, cambios concurrentes y deadlock 1205 recuperado. Los IDs/valores publicados son ficticios; no se incluyen tokens, contraseñas, cookies o hashes de datos operativos.

Al finalizar se cerraron únicamente el Chrome/perfil y servidor propios de QA, se retiraron los catorce esquemas de fixture en orden y se comprobó **cero tablas en fresapos_test** antes de la regresión final. No se ejecutó limpieza sobre fresapos. Azure SQL, cPanel, hardware físico, impresoras y una caída real de red SQL no están verificados por estas capturas.
