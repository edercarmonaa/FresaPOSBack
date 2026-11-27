# Reportes administrativos · UI-07D

La presentación de `/reports` y `/reports/{report}` conserva las nueve variantes existentes, las definiciones de filtros y columnas y los datos entregados por el servicio. UI-05 ya aportaba los tokens, indicadores y estilos principales; UI-07D añade un refinamiento local sin reescribir esa base.

| Superficie | Archivo en `Modules/Reports/resources/views/` | Cambio visual | Contrato conservado | Verificación |
|---|---|---|---|---|
| Página y nueve variantes | `index.blade.php` | Contexto CSS administrativo y clases compartidas | Rutas, valores, etiquetas, notas y columnas | ReportAdministrationPresentationTest; ReportsHttpTest |
| Navegación | Misma vista | Nueve columnas en pantalla amplia, tres en escritorio/tablet y dos en móvil | Destinos, rango conservado y aria-current | Matriz y comparación DOM |
| Filtros y acciones | Misma vista | Controles compartidos, espaciado y foco | GET, campos, límites, script y reglas de fechas | Contrato de formularios y comparación FormData |
| Resultados | Misma vista | Ancho legible en escritorio; scroll local con teclado; tarjetas móviles existentes | Datos completos, precisión y enlaces autorizados | Contrato DOM, SQL y navegador |
| Exportación | Misma vista, botones existentes | Clases visuales | POST, CSRF, permiso y límites de 300/1000 filas | Fronteras exactas y ReportExportsTest |
| Error web | `error.blade.php` | Jerarquía, separación y foco | Mensajes escapados, rol alert y enlace de retorno | Pruebas de errores y HTTP real |

`public/css/ui/report-admin.css` se carga después de `reports.css`, únicamente en las dos vistas. El archivo de UI-05 se conserva. El contexto nuevo `.ui-report-admin-page` limita las reglas adicionales. No hay JavaScript nuevo de producto ni cambios en controladores, servicios o consultas.

La tabla utiliza scroll dentro de su región existente en escritorio cuando sus columnas requieren más espacio. Los importes no se truncan; mantienen su cadena y precisión. A 1100 px y menos se conserva la presentación vertical de UI-05; el ajuste de box-sizing evita sumar padding al ancho completo de cada celda. La cabecera conserva su estructura semántica, pendiente de evaluación con lector de pantalla.

Los botones de exportación siguen siendo inaccesibles cuando el usuario no tiene `reports.export`. PDF mantiene el límite de 300 filas y Excel, 1000. Los exportadores, plantillas PDF, archivos XLSX y auditoría no se modifican. Las pruebas existentes de exportación se ejecutan únicamente en la base independiente.

El JavaScript de filtros es idéntico: los períodos predefinidos deshabilitan fechas; Personalizado las habilita; los campos opcionales vacíos se omiten al enviar. La prueba de navegador compara FormData antes y después, previniendo el envío real. El gráfico conserva las barras y valores proporcionados; no se crea una serie nueva ni indicadores ficticios en la aplicación.

[Informe](entregas/UI-07D.md), [QA reproducible](qa/ui-07d/README.md), [integridad](qa/ui-07d/source-integrity.json).
