# Evidencia visual de Catalog — 1.4d

Revisión ejecutada el 13 de noviembre de 2026 con **Chrome 157.0.8103.51 headless real**, DevTools Protocol 1.3, escala 1 y los viewports CSS solicitados. No son maquetas ni capturas de HTML sin JavaScript. [Informe y límites](../../entrega-1.4d.md).

Servidor de QA aislado en 127.0.0.1:8082, SQL Server `fresapos_test`, usuario/catálogo/sesiones/archivos de fixtures. La conexión operativa se excluyó del proceso QA. Servidor y navegador se cerraron al terminar; la base de pruebas quedó vacía y las quince tablas operativas, credenciales y configuración permanecieron intactas.

## Pantallas principales

| Tamaño | Productos | Categorías |
| --- | --- | --- |
| 1440 × 900 | [Captura](products-list-1440x900.png) | [Captura](categories-list-1440x900.png) |
| 1024 × 768 | [Captura](products-list-1024x768.png) | [Captura](categories-list-1024x768.png) |
| 768 × 1024 | [Captura](products-list-768x1024.png) | [Captura](categories-list-768x1024.png) |
| 390 × 844 | [Captura](products-list-390x844.png) | [Captura](categories-list-390x844.png) |

Hay **36 capturas de matriz**, nueve por tamaño: listado de productos, tabla desplazada hacia las acciones, paginación, edición superior/inferior, búsqueda sin resultados, listado de categorías, formulario con error de duplicado y búsqueda de categorías vacía. Sus nombres incorporan escenario y resolución; las rutas y medidas constan en [visual-report.json](visual-report.json).

No hay desbordamiento horizontal del documento. En móvil las tablas tienen scroll horizontal propio; se comprobó hasta las acciones de [productos](products-actions-390x844.png) y [categorías](categories-actions-390x844.png). La navegación se distribuye en dos líneas. Formularios, nombres largos, mensajes, botones, imágenes y estados vacíos mantienen su disposición mediante scroll vertical normal. Los inputs y selects largos utilizan su comportamiento nativo.

## Interacciones adicionales

Ocho capturas adicionales, **44 PNG en total**:

- [Acciones de categorías en móvil](categories-actions-390x844.png).
- [Foco visible con teclado](keyboard-focus-390x844.png): Tab por navegación/controles, Enter abre formulario y Tab pasa al textarea.
- [Precio inválido y datos conservados](product-price-error-390x844.png).
- [Subida en curso](image-upload-loading-390x844.png).
- [Vista previa cargada](image-upload-preview-390x844.png).
- [Guardado en curso y botón deshabilitado](save-loading-disabled-390x844.png).
- [Mensaje tras creación](product-created-message-390x844.png).
- [Papelera y restauración](trash-restore-390x844.png).

[interactions-report.json](interactions-report.json) registra los escenarios completados y las peticiones fetch observadas, sin errores JavaScript. [confirmations.json](confirmations.json) contiene los cinco diálogos JavaScript detectados: se canceló un borrado y se aceptaron desactivar, activar, borrar y restaurar. Los diálogos nativos se manejaron mediante CDP; sus píxeles no aparecen en estas capturas.

La carga/guardado se ralentizó mediante emulación de red para observar sus indicadores y realizar dos clics consecutivos. El botón quedó deshabilitado y se comprobó en SQL **una sola fila** para el producto creado desde navegador, activo tras restauración y con precio exacto `42.5000`.

## Contraste y alcance

[contrast-report.json](contrast-report.json): 105 muestras de texto/controles habilitados del listado, colores computados convertidos a sRGB, contraste mínimo 6,42:1 y ninguna muestra inferior a 4,5:1. No cubre todos los estados, placeholders ni anuncios de lector de pantalla y no constituye certificación WCAG.

Se revisaron viewports en Chrome de Windows; no dispositivos táctiles físicos, teclado virtual, Safari/Firefox o lectores de pantalla. No se encontraron defectos visuales que requirieran modificar la aplicación. Esta ampliación solo incorpora documentación y evidencia; no se repitió la suite PHP porque no hubo cambios de código productivo. Se conserva el resultado real previo de 273 pruebas/2454 aserciones.
