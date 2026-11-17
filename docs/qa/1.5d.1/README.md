# Revisión visual de Cash 1.5d.1

17 de noviembre de 2026. Chrome 157.0.8103.51 headless real/DevTools, PHP/Livewire nativos, servidor QA 127.0.0.1:8083. Conexión exclusiva fresapos_test, perfil/cookies/credenciales de fixture separados. Sin aperturas en fresapos.

22 PNG y [mediciones](visual-report.json). Resoluciones 1440×900, 1024×768, 768×1024 y 390×844: formulario Cash, POS sin caja, Cash abierta y POS con caja. Además caja sin aprovisionar, error decimal conservando entrada, confirmación, mesero sin divulgación del fondo, Cash denegado y sesión revocada. Cero errores JS, desbordamientos horizontales o botones visibles menores de 44 px.

Apertura de fixture: una sesión, fondo 125.5000 y un evento auditado. Venta POS preparada mediante servicios/Cash reales en esa base aislada. Apertura/agregado del POS siguen bloqueados por idempotencia Sales; no se registran adaptadores ficticios productivos. Revocación SQL real y refresco Livewire nativo comprobados.

Computer-use tuvo dos fallos de inicialización; se documenta Chrome/DevTools como alternativa. Procesos cerrados/fixtures retiradas con TestDatabaseGuard; cero tablas antes de regresión final. Nombres/IDs son de fixture; capturas sin contraseñas/tokens. No es auditoría exhaustiva de accesibilidad.
