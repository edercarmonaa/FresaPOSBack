# Revisión visual del POS — 1.5c

**Chrome 157.0.8103.51 headless real**, DevTools, JavaScript y Livewire activos; no son maquetas. Servidor aislado 127.0.0.1:8083, SQL Server fresapos_test con guard, sesiones/archivos/usuario/ventas de fixture y conexión operativa excluida. Servidor y navegador cerrados al terminar. [Informe de entrega](../../entrega-1.5c.md).

Hay **24 capturas de matriz**: mostrador, catálogo, WebP, venta, mesas y búsqueda vacía en cada tamaño; y **cinco capturas adicionales** de interacción. Sin desbordamiento horizontal, errores JavaScript ni botones visibles menores de 44 px en las 29 mediciones. Se revisaron visualmente distribución, contraste, texto, estados, importes y controles. Esto no sustituye una auditoría completa de accesibilidad.

| Tamaño CSS | Catálogo | Mesas | Venta |
| --- | --- | --- | --- |
| 1440 × 900 | [Ver](catalog-1440x900.png) | [Ver](tables-1440x900.png) | [Ver](sale-1440x900.png) |
| 1024 × 768 | [Ver](catalog-1024x768.png) | [Ver](tables-1024x768.png) | [Ver](sale-1024x768.png) |
| 768 × 1024 | [Ver](catalog-768x1024.png) | [Ver](tables-768x1024.png) | [Ver](sale-768x1024.png) |
| 390 × 844 | [Ver](catalog-390x844.png) | [Ver](tables-390x844.png) | [Ver](sale-390x844.png) |

Los archivos counter-, image- y empty- de cada tamaño completan la matriz. En los cuatro tamaños se comprobó carga real de WebP. En 768 y 390 se verificó con Tab la retención del foco dentro del panel, retorno al cerrarlo y apertura táctil a 390.

Interacciones adicionales, con Cash de fixture habilitado exclusivamente en el bootstrap privado de QA; idempotencia productiva sigue indisponible, apertura/agregado continúan deshabilitados:

- [Guardado y bloqueo de doble envío](saving-disabled-390x844.png): dos clics dejaron cantidad 3, total exacto 161.5000 y **un evento sales.quantity_changed**. No se demuestra idempotencia durable con este caso.
- [Error de nota](note-validation-390x844.png): HTML rechazado por Sales, texto pendiente conservado, mensaje visible dentro del panel móvil.
- [Nota guardada](note-saved-390x844.png): normalización/operación a través del servicio real, sin alterar el precio histórico.
- [Sin conexión](offline-390x844.png): red offline mediante Chrome; mensaje visible, sin operación confirmada ni borrado del formulario.
- [Sesión revocada](session-expired-390x844.png): revocación real del acceso de fixture en SQL y petición POS posterior rechazada; aviso 401 sin abandonar el formulario. La interfaz pide JSON en sus peticiones Livewire; navegación web normal conserva redirección al login.

Hallazgos corregidos: navegación excesiva en móvil, etiquetas partidas y errores que inicialmente quedaban detrás del panel. El primer sondeo 419 en Chrome fue **inconcluyente**: no se contabiliza como caso aprobado de navegador. La comprobación CSRF 419 y sesión revocada del protocolo real de Livewire sí se ejecuta en PHPUnit; no se desactivó el middleware. Laravel 13 admite también verificación de origen same-origin.

Medidas y pruebas: [matriz](visual-report.json), [interacciones](interactions-report.json), [sesión revocada](session-report.json). No contienen credenciales ni tokens. Las capturas de ventas existentes son fixtures; no se abrieron ventas en fresapos ni se habilitó Cash productivo.
