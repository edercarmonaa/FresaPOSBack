# Caja · presentación UI-07C

La ruta existente `/cash` conserva sus middleware y permisos. La página y las tres vistas hijas usan los tokens de tema y las clases de controles compartidos; no se agrega JavaScript de módulo. Las operaciones se coordinan mediante las clases Livewire y servicios existentes, que no se modifican.

| Superficie | Archivo en `Modules/Cash/resources/views/` | Cambio visual | Garantía conservada | Prueba asociada |
|---|---|---|---|---|
| Página | `desk.blade.php` | CSS local y recursos administrativos una vez | Ruta, acceso y composición Livewire originales | CashPresentationHttpTest |
| Apertura | `livewire/cash-desk.blade.php` | Jerarquía, tarjeta de sesión y campo monetario | Autorización, clave de apertura y fondo como cadena | CashHttpTest; CashPresentationTest |
| Recuperación de conexión | Misma vista | Mensaje y enlace con contraste de tema | Script de interceptación byte por byte y campos conservados | CashPresentationTest; capturas de red |
| Movimientos | `livewire/movements-panel.blade.php` | Totales, campos, tabla y botones | Importe, concepto, bindings, disabled y recuperación de intención | CashFinanceTest, CashFinanceHttpTest, CashFinanceLivewireTest |
| Arqueo ciego | `livewire/closing-panel.blade.php` y condición del padre | Campos y mensajes de conteo | Fondo y movimientos ocultos durante conteo; pendientes bloquean cierre | CashReconciliationLivewireTest; contrato DOM |
| Resumen e historial | `livewire/closing-panel.blade.php` | Superficies neutrales y disposición flexible | Valores persistidos, diferencia firmada, fechas UTC y enlaces al POS | CashReconciliationTest y CashReconciliationHttpTest |

`public/css/ui/cash.css` se carga solo en Caja. Sustituye estilos inline y limita sus reglas al contexto de Caja y su tabla. Conserva los elementos nativos y todos sus atributos funcionales. Los importes mantienen las cadenas originales y la precisión recibida; se utiliza tipografía tabular sin recalcular, redondear ni truncar datos.

Los formularios y detalles tienen dos columnas en escritorio y una por debajo de 768 px. Los movimientos usan la tabla existente; a 640 px y menos, cada fila se presenta verticalmente con los `data-label` originales. La cabecera se oculta visualmente manteniéndola disponible para tecnologías de asistencia; la evaluación real con lector de pantalla sigue pendiente.

Los mensajes generales son informativos, los errores usan el tono de error y los pendientes/desconexión usan advertencia. El resumen de cierre tiene superficie neutral: el texto original distingue caja cuadrada, faltante o sobrante. No se agrega una confirmación, modal, cálculo, permiso ni operación nueva.

El conteo mantiene la advertencia de cierre definitivo y la observación obligatoria. No se muestran el fondo inicial ni el panel de movimientos del turno durante el arqueo ciego. El historial autorizado de turnos anteriores conserva su comportamiento. Los bloqueos y permisos siguen perteneciendo al servidor.

[Informe de 22 apartados](entregas/UI-07C.md), [QA reproducible](qa/ui-07c/README.md), [integridad](qa/ui-07c/source-integrity.json).
