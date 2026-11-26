# UI-05 · Indicadores administrativos existentes

La pantalla existente `/reports`, cuyo reporte predeterminado es `summary`, concentra los indicadores administrativos implementados. Esta entrega moderniza esa pantalla compartida y sus errores. El inicio público y `/admin` mantienen sus funciones actuales; no se crean rutas, consultas ni un segundo origen de indicadores.

## Datos y límites de alcance

La vista consume el mismo `ReportData`, `ReportDefinition` y `ReportPresentation`: totales del conjunto filtrado, ingreso por día comercial cuando existe serie, criterios y resultados paginados. El contenido, precisión, valores cero, valores nulos («No disponible»), fechas, zona comercial y reglas de exportación se conservan.

La vista es compartida por Resumen, Ventas, Productos vendidos, Métodos de pago, Movimientos de efectivo, Sesiones Cash, Arqueos y cierres, Descuentos y Correcciones. Se conserva la navegación y la capacidad de consultar cada variante. Se excluyen alertas de inventario, actividad reciente y tendencias adicionales porque no están implementadas como indicadores de esta pantalla. No se añaden gráficas simuladas ni indicadores ficticios a la aplicación.

## Presentación

`public/css/ui/reports.css` se carga mediante la pila `styles` únicamente en las dos vistas de Reportes. Usa los tokens y preferencias existentes, tarjetas de indicadores, números tabulares, controles de al menos 44 px, estados deshabilitados y foco visible. Las cifras se presentan sin convertir su tipo o recalcular importes.

Se elimina de Reportes el límite cromático claro de UI-03 porque sus vistas ya usan la paleta semántica completa. POS conserva su límite claro. El SVG de la gráfica usa `currentColor`; su escala, datos y cálculo máximo existentes permanecen iguales.

La tabla conserva la presentación por tarjetas en pantallas de hasta 1100 px y todos sus campos; sus encabezados quedan ocultos visualmente con recorte, conservados en el documento. En escritorio usa la tabla completa. Se incorpora una región desplazable y enfocable alrededor del detalle; la gráfica también puede recibir foco para desplazarse cuando la serie lo necesita. No se altera el orden de los filtros ni las acciones.

El JavaScript inline existente de rangos de fecha y exclusión de filtros vacíos permanece idéntico byte por byte. CSRF, permisos de exportación, límites PDF/Excel, enlaces de detalle y paginación se comparan con la vista anterior. Los documentos PDF y XLSX no se rediseñan.

## Validación y revisión

[Informe de entrega](entregas/UI-05.md), [QA y comandos](qa/ui-05/README.md), [resultados](qa/ui-05/quality.json), [integridad](qa/ui-05/source-integrity.json), [matriz responsive](qa/ui-05/responsive.csv).

Aplicación real: http://127.0.0.1:8080/reports, con un acceso existente que tenga permiso. QA visual separado: http://127.0.0.1:8095/after-summary.html. Sus datos se identifican como ficticios; no hay sesión autenticada, conexión SQL ni operaciones. Las versiones anteriores se reconstruyen desde Git y recuperan su límite cromático claro original dentro del layout compartido.

UI-04 se registra aceptada en el [índice](ESTADO.md). UI-05 queda pendiente de aceptación. No se inicia UI-06.
