# Motivos autorizados · UI-07B

La ruta existente `/admin/sales/reasons` utiliza los tokens, controles y preferencias del Design System. El formulario permanece en línea; los diálogos de confirmación pertenecen a Livewire y conservan su texto original.

| Superficie | Archivo | Cambio | Riesgo protegido | Verificación |
|---|---|---|---|---|
| Página | `Modules/Sales/resources/views/reasons.blade.php` | CSS local y recursos administrativos una vez | Middleware y permiso `settings.update` | ReasonsPresentationHttpTest; SaleReasonsWebTest |
| Formulario | `Modules/Sales/resources/views/livewire/reason-administration.blade.php` | Campos y acciones con clases compartidas; dos columnas desde 768 px | Código/operación inmutables; fieldset congelado tras envío; confirmación | ReasonsPresentationTest, nueve estados; SaleReasonsWebTest |
| Filtros y tarjetas | Misma vista | Superficies, separación, ajuste de textos | Bindings, IDs, orden y operaciones idénticos | Contrato DOM anterior/actual y SaleReasonsHttpTest |
| Mensajes | Misma vista | Información azul, error rojo y desconexión ámbar en ambos temas | Mantener roles, textos e intención sin confirmar | Contratos, contraste y capturas de mensajes |
| Paginación | Misma vista | Botones y disposición flexible | Expresiones de página y disabled originales | Estados primera/última página |

`public/css/ui/reasons.css` solo alcanza `.ui-reasons-module` y `.ui-reasons-page`. Reutiliza las clases de controles y acciones existentes sin transformar los elementos HTML ni sus atributos funcionales. No se agrega JavaScript propio. Los mensajes generales de estado usan tono informativo: también pueden describir un resultado todavía no confirmado.

Los campos deshabilitados siguen usando los mecanismos nativos (`fieldset disabled` y `disabled` de código/operación). La presentación distingue esos estados sin habilitarlos. No hay nuevas acciones, cancelaciones, consultas, validaciones ni cambios en la recuperación idempotente.

QA usa datos ficticios, sin SQL ni sesión operativa. La integración real corre exclusivamente en `fresapos_test`, con sus salvaguardas existentes. [Informe](entregas/UI-07B.md), [reproducción](qa/ui-07b/README.md), [resultados](qa/ui-07b/quality.json).
