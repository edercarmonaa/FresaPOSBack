# POS: cat?logo, mesas y resumen ? UI-08A

La ruta existente `/pos` carga `public/css/ui/pos.css` una sola vez. Se aplica al contexto `ui-pos-page` y al componente `ui-pos-module`, con los tokens locales del Design System, Inter, temas claro/oscuro/autom?tico y foco visible. El layout compartido conserva sus bytes.

El cat?logo conserva b?squeda, categor?as, im?genes, selecci?n y paginaci?n. Las mesas conservan sus estados libre, ocupada e inactiva. El resumen conserva cantidades, notas, importes e historial. No se a?aden c?lculos ni acciones de negocio.

El breakpoint m?vil sigue siendo 1024 px. El panel mantiene Alpine, di?logo, trampa de foco y Escape. El enfoque del encabezado espera 30 ms despu?s del siguiente tick: permite que la trampa instalada recuerde primero el bot?n de apertura y pueda devolverle el foco al cerrar. El interceptor de red y Livewire no cambia.

Los paneles de cobro, operaciones, descuentos, correcciones y comprobante se agrupan en `ui-pos-financial ui-legacy-light`. Sus condiciones, claves y componentes se conservan; sus estilos y plantillas no se modifican. Su modernizaci?n corresponde a UI-08B, pendiente de autorizaci?n.

[Informe](entregas/UI-08A.md) ? [Reproducci?n QA](qa/ui-08a/README.md) ? [Estado](ESTADO.md).
