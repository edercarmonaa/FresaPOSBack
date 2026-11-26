# Fixtures UI-05

`index.blade.php` y `error.blade.php` son copias del commit `ee90df7`, usadas exclusivamente para comparar contenido, filtros, controles y permisos antes/después. `ReportFixture.php` proporciona datos ficticios de presentación sin consultas SQL. No representa un cierre financiero real ni valida conciliación de importes.

La comparación cubre los nueve tipos de reporte, exportación permitida/denegada y totales de filas 0, 301 y 1001. Estos casos fuerzan límites visuales y de controles, sin crear operaciones. Las pruebas de integración existentes verifican el comportamiento real con SQL Server independiente.
