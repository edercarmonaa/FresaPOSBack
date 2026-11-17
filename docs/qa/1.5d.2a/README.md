# Revisión visual 1.5d.2a

Chrome 157 headless real sobre PHP/Livewire local (puerto aislado 8083). Exclusivamente fresapos_test, usuario/productos/mesas ficticios; ningún acceso administrativo operativo. El motor de automatización Windows no inició y se utilizó CDP como alternativa. Evidencia del 17 de noviembre de 2026.

Se revisaron 1440×900, 1024×768, 768×1024 y 390×844. `visual-report.json` registra dimensiones, ausencia de desbordamiento horizontal y errores JavaScript, y cero botones visibles menores de 44 px. Las capturas conservan el desplazamiento del escenario para mostrar sus controles.

- `no-cash-*`: apertura bloqueada sin sesión.
- `cash-open`: apertura del fixture con fondo cero.
- `lost-response`: respuesta Livewire descartada en Chrome después de que el servidor devolvió HTTP 200.
- `recovered-counter`: recarga recupera la misma venta mediante recibo SQL.
- `counter-item`, `counter-*`, `paused`: producto, cantidad, nota y pausa/recuperación.
- `table-*`, `table-recovered`: mesa ocupada una sola vez y selección recuperada.

La consulta SQL posterior confirmó una sesión de caja, dos ventas sin folio, una línea de tres unidades a 10.1234 con total 30.3702 y siete recibos completed. Apertura=2 eventos; agregado, cantidad, nota, pausa y recuperación=1 evento cada uno. Esta revisión no sustituye las pruebas automatizadas de doble clic/snapshot, pending, conflictos, concurrencia y recuperación; esas se ejecutan contra SQL Server con procesos independientes.

Fixtures eliminados al terminar. El servidor de QA y Chrome se cerraron. La pérdida de respuesta fue una intervención de CDP en la respuesta HTTP, no un corte físico de SQL Server. No se ensayó Android, pagos o cierre.

Segunda revisión con fixture nuevo: doble clic físico al abrir/agregar produjo una sola venta/línea y un evento por cambio. Se observaron carga real con latencia de Chrome (una petición pendiente, diez controles deshabilitados) y conflicto de revisión tras una nota externa; el borrador sobrevivió Actualizar vista. `double-click-real`, `loading-real`, `conflict-real` y `conflict-refreshed` completan **22 capturas**. Una segunda limpieza dejó fresapos_test sin tablas de usuario.

Reportes adicionales: [OpenAPI](openapi-report.json), [integridad operativa](integrity-report.json), [tipos/constraints](schema-report.json) y [deadlock SQL 1205 real](real-deadlock.json). Los archivos race-* resumen las ocho carreras de procesos HTTP independientes. No contienen credenciales, SQL, cuerpos de peticiones ni datos operativos de usuarios.
