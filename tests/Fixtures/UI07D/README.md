# Fixtures de Reportes · UI-07D

`index.blade.php` y `error.blade.php` son copias de las vistas al inicio de UI-07D, después del merge observado. No se editan para ajustar las comparaciones. `FixtureData` reutiliza la fábrica de UI-05 sin modificarla y añade estados deterministas de límites, paginación, rango predefinido, escape y ausencia de importes de cierre en una sesión abierta.

Los datos son ficticios, sin consultas SQL. Las cifras y columnas de las vistas anteriores y nuevas se comparan con el mismo DTO. Los escenarios de límite representan conteos totales del conjunto filtrado con una muestra visual de filas; no son una exportación de ese conjunto. Los nombres con etiquetas permiten verificar escape.

La comparación incluye todos los nodos, atributos funcionales, textos y atributos SVG dentro de main, excluyendo únicamente clases CSS. Se conserva además el JavaScript byte por byte. Los permisos de exportación se simulan con Gate en memoria; las pruebas SQL existentes verifican autorizaciones reales.
