# Catálogo UI-06

Abrir http://127.0.0.1:8096/ mientras esté activo el servidor QA. [HTML exportado](index.html), [fuente](catalog.blade.php), [layout](layout.blade.php).

Muestras documentales de componentes Blade reales. Sin sesión, conexión SQL, consulta o operación de negocio. Los atributos Livewire ilustran su conservación; no hay hidratación de componentes funcionales. El formulario solo ejecuta validación nativa y bloquea su envío en el catálogo.

Se muestran formularios, filtros, tabla con desplazamiento, paginación suministrada, modal, panel lateral, mensajes y estados ficticios. El loading es un ejemplo estático, no una consulta. El controlador de producto solo abre/cierra elementos marcados y no implementa las acciones de los slots.

La comparación anterior es el [catálogo UI-02 preservado](../ui-02/README.md): sus ocho componentes continúan intactos. Los patrones nuevos de UI-06 no tenían una versión anterior compartida. No se presenta un cambio antes/después de módulos que no se han intervenido.

[Guía de adopción](../../componentes-ui06.md), [resultados](../../qa/ui-06/README.md).
