# Design System FresaPOS UI 1.0 — UI-02

26/11/2026 · Pendiente de aceptación. Implementa la base visual de la propuesta UI-01 aceptada por el responsable. [Catálogo](catalogo/ui-02/README.md), [tokens](06-design-tokens.md), [componentes](07-componentes.md), [QA](qa/ui-02/README.md).

## Alcance implementado

CSS propio con variables, componentes anónimos Blade y un controlador de tema exclusivamente visual. Funciona sin compilación, Node, Vite, CDN ni paquetes nuevos. No sustituye Tailwind/Alpine/Livewire existentes ni modifica el layout actual. La aplicación continúa usando su presentación anterior hasta autorizar las etapas correspondientes.

```text
public/css/ui/tokens.css              primitivos → semánticos → componentes
public/css/ui/components.css          tipografía, controles y estados
public/js/ui/theme.js                 claro / oscuro / automático
public/fonts/ui/                      Inter original, licencia y procedencia
resources/views/components/ui/       ocho componentes anónimos Blade
docs/ui-ux/catalogo/ui-02/            catálogo documental renderizado con Blade
docs/ui-ux/qa/ui-02/                  exportador, servidor cerrado, pruebas y evidencias
```

El catálogo usa exactamente los archivos de public y los componentes Blade de resources. Su HTML es una exportación reproducible, no una copia artesanal de los componentes. La duplicación del binario de Inter respecto al prototipo UI-01 conserva intacto el artefacto histórico aprobado; la aplicación futura consumirá únicamente la copia de public. Ambas tienen el mismo SHA y licencia original.

## Activación deliberada

El sistema se habilita agregando `ui-root` al elemento HTML y cargando el script de tema en head **antes** de las hojas tokens/components. La clase `ui-page` aplica el fondo cuando corresponda. Todos los nombres nuevos usan prefijo `ui-` o `--ui-`; no se introducen resets globales de botones, campos o encabezados. Las capas CSS son `ui.tokens`, `ui.base`, `ui.components`.

No se añadió esa activación a ningún layout funcional en UI-02. El script y las fuentes no se descargan en las pantallas existentes. Una prueba nueva confirma que el inicio sigue sin cargar estos recursos. La integración y convivencia con utilidades existentes deben verificarse en UI-03; las reglas sin capa pueden prevalecer sobre CSS con capa, por lo que no deben mezclarse clases de colores contradictorias.

## Temas

`theme.js` aplica el atributo `data-ui-theme` resuelto como light/dark y `data-ui-theme-mode` con auto/light/dark. Automático escucha `prefers-color-scheme`; una elección explícita prevalece. Se puede asociar un select etiquetado mediante `data-ui-theme-select`. API visual: `FresaUITheme.get()`, `.set('dark')` y `.reset()`.

La clave `fresapos.ui.visual.v1` contiene únicamente `{ "theme": "auto" }` o una variante válida. JSON corrupto, campos ajenos y valores desconocidos se normalizan; storage bloqueado usa memoria. El script se carga antes del CSS para aplicar el tema antes del cálculo inicial. No se midió una tasa de parpadeo con filmación; no se promete ausencia absoluta en cualquier entorno.

La clave histórica del prototipo UI-01 es distinta y no se migra: era documental. Densidad se admite como atributo visual `data-ui-density` y el catálogo la demuestra sin persistirla. Menú, tamaño de texto y preferencias completas de layouts corresponden a UI-03. No existen tablas, endpoints o servicios para preferencias.

## Accesibilidad y seguridad de integración

Objetivo WCAG 2.2 AA, con contrastes semánticos comprobados, foco visible, controles activos de referencia 44×44 px, texto además del color, tipos nativos, regiones de tabla etiquetadas y reducción de movimiento del SO. El componente de error no decide reglas de validación ni consulta errores por sí mismo. Las alertas no imponen roles vivos a mensajes estáticos. Los iconos son decorativos; la acción contenedora requiere nombre accesible.

Nunca reemplazar confirmaciones, PIN, guards, campos ocultos, CSRF, `wire:*`, restricciones de cobro o arqueo por una variante visual. Los componentes no implementan permisos, pagos, importes, consultas ni lógica financiera. No hay modales, navegación o paginación funcional nuevos; sus patrones se integrarán en las etapas autorizadas.

## Mantenimiento

Añadir colores en la capa semántica y variantes en el componente dueño. No copiar CSS a cada módulo. El texto dinámico usa escape Blade; no pasar HTML no confiable a slots con salida sin escape. Mantener IDs y asociaciones explícitas. Para una pantalla nueva: seleccionar componentes, conservar contrato, comprobar temas/estados/teclado/reflujo y ejecutar regresión apropiada.

Versionado documental: UI 1.0 registra esta base; cambios posteriores documentarán variantes y evidencia sin sobrescribir los manifiestos UI-00/UI-01/UI-02. Tickets, comprobantes y exportaciones siguen fuera del sistema visual web de esta entrega.
