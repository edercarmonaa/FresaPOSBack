# UI-07A · Catálogo: productos y categorías

27/11/2026. Primera subetapa de integración de módulos administrativos. UI-06 se registra aceptada al autorizar continuar con UI-07, comenzando por Catálogo. La entrega se limita a productos y categorías.

## Presentación aplicada

Las dos páginas solicitan `catalog.css` y los recursos compartidos UI-06 mediante las pilas Blade. Las vistas Livewire reutilizan filtros, inputs, select, textarea, botones, tabla y paginación. Se mantienen formularios en línea, confirmaciones `wire:confirm`, panel de papelera y todos los permisos originales. No se incorporan modales o paneles laterales a los flujos.

Los mensajes mantienen sus elementos nativos, roles y regiones vivas para no alterar anuncios existentes. Los errores de campo conservan IDs, aliases, mensajes y lógica; cambia solo su clase visual. Los estados vacíos permanecen dentro de la tabla y los indicadores de carga siguen a cargo de Livewire.

La tabla conserva todas sus columnas, caption visible, encabezados con `scope="col"` y desplazamiento horizontal mediante teclado. No se ocultan acciones o columnas según tamaño. La clase `overflow-x-auto` se conserva también como contrato verificado por una prueba previa. El precio sigue pasando por `CatalogPresentation::money` y el campo mantiene tipo text, inputmode decimal y hasta cuatro decimales sin nueva conversión.

Los checkboxes siguen siendo nativos de 22 px dentro de etiquetas con área táctil mínima de 44 px. Se usan tonos semánticos para mensajes y acciones destructivas, foco y bordes de error. Los botones cambian el fondo inmediatamente al cambiar tema para evitar mezclas transitorias de bajo contraste; solo se conserva transición de borde, respetando la preferencia de movimiento.

## Integridad

Se conservan métodos y nombres, IDs, tipos efectivos, atributos requeridos, límites, opciones, estados disabled, bindings, debounce, claves de fila, confirmaciones, condiciones, mensajes, imágenes, lazy loading y textos alternativos. En paginación se usan bindings booleanos `:disabled` con las mismas expresiones originales. El cambio no calcula páginas nuevas ni modifica el servicio.

No se modifican clases Livewire, servicios, DTOs, consultas, middleware, rutas, permisos, modelos, migraciones, contratos API, almacenamiento, procesadores de imagen, dependencias o configuración SQL. Las opciones de categoría actual inactiva, permisos diferenciados, papelera, eliminación lógica, restauración y precio cero permanecen verificadas.

UI-06 seguía sin commit al inicio. La huella inicial incluye archivos versionados y no versionados: todos se conservan, excepto la actualización autorizada del índice evolutivo. No se hicieron commits ni pushes.

## Revisión

Aplicación: http://127.0.0.1:8080/admin/catalog/products y http://127.0.0.1:8080/admin/catalog/categories, con una cuenta ya autorizada. Las rutas anónimas continúan redirigiendo al acceso.

Las fixtures visuales de http://127.0.0.1:8097/after-products-edit.html y http://127.0.0.1:8097/after-categories-edit.html son documentales. No tienen sesión, SQL, operaciones o hidratación Livewire. El exportador rellena campos con estado ficticio para inspección visual y oculta los mensajes de loading en reposo solo dentro de esas fixtures. No validan E2E de negocio. Los flujos reales se prueban mediante HTTP/Livewire con SQL Server independiente.

[Informe](entregas/UI-07A.md), [QA](qa/ui-07a/README.md), [integridad](qa/ui-07a/source-integrity.json), [matriz](qa/ui-07a/responsive.csv). Integración del siguiente módulo pendiente de autorización.
