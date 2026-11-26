# Componentes base — UI 1.0

Componentes anónimos Blade en [resources/views/components/ui](../../resources/views/components/ui). Listos para consumo; **todavía no integrados** en vistas funcionales. [Catálogo real](catalogo/ui-02/README.md).

| Componente | Propiedades | Contrato visual |
|---|---|---|
| `x-ui.button` | variant: primary/secondary/danger | button nativo, type=button predeterminado; type=submit explícito; atributos/slot conservados |
| `x-ui.badge` | tone: neutral/info/success/warning/error | Etiqueta sin prometer estado de negocio |
| `x-ui.card` | title opcional | section, título H2 opcional y slot |
| `x-ui.input` | invalid booleano | input de texto predeterminado; tipo, name, ID, wire y atributos por consumidor |
| `x-ui.field` | input-id, label; hint/error/required opcionales | Etiqueta asociada, slot y mensajes escapados |
| `x-ui.alert` | tone: info/success/warning/error; title opcional | Icono decorativo y texto; no agrega role/aria-live automáticamente |
| `x-ui.table` | caption requerido | Región enfocable y etiquetada, tabla con caption; slot contiene thead/tbody |
| `x-ui.icon` | name | SVG lineal decorativo de lista cerrada; sin biblioteca nueva |

Variantes desconocidas vuelven a primary/neutral/info según corresponda. El slot no se convierte a HTML desde cadenas no confiables. Los atributos se fusionan mediante ComponentAttributeBag; el consumidor conserva los bindings y eventos originales. `disabled` no representa autorización ni protección contra duplicidad del servidor.

## Uso y asociaciones

```blade
<x-ui.button type="submit" wire:loading.attr="disabled">
    Guardar
</x-ui.button>

<x-ui.field input-id="product-name" label="Nombre"
    hint="Nombre visible del producto." :error="$nameError">
    <x-ui.input id="product-name" name="name" wire:model="name"
        aria-describedby="product-name-help product-name-error"
        :invalid="(bool) $nameError" />
</x-ui.field>
```

El ejemplo es ilustrativo, no un formulario añadido al sistema. Debe incluirse `product-name-error` en aria-describedby **solo cuando ese mensaje exista**. El componente field crea IDs `<input-id>-help` y `<input-id>-error` cuando sus textos están presentes; el llamador asocia los IDs existentes y conserva los funcionales. `required` en field solo muestra el texto «obligatorio»; la validación y el atributo nativo pertenecen al input original. No inferir ni cambiar reglas.

Select/textarea nativos se pueden estilizar con `ui-select` y `ui-textarea`, conservando todos sus atributos. No hay wrappers nuevos para paginación, filtros, confirmaciones, PIN, diálogos o Livewire PHP. Los slots evitan duplicar lógica; los mensajes reales los entrega el flujo existente.

## Iconos y acciones

Nombres disponibles: info, check, warning, plus, search, close, sun, menu. Nombre desconocido usa info. El SVG siempre es decorativo (`aria-hidden`, `focusable=false`); un botón que solo contiene icono debe tener `aria-label` o texto visible equivalente. No añadir atributos ARIA contradictorios al icono. Si falta un icono, ampliar la lista única y comprobar significado visual, sin incorporar otra biblioteca.

## Tablas y estados

El slot de table contiene filas semánticas con `th scope=col/row`; `ui-numeric` alinea cantidades. El wrapper desplaza la tabla cuando haga falta y admite foco con teclado. Sus atributos se aplican al wrapper, no a table; no cambiar IDs funcionales sin revisar su destino original. El caption es visible. No incluye ordenamiento, consultas, filtros o paginación: conserva los existentes al integrarse.

`ui-empty` ofrece una apariencia vacía; su texto debe reflejar el estado real. Los mensajes flotantes y cambios de densidad del catálogo son demostraciones documentales. No son un nuevo sistema de notificaciones del producto.

## Comprobación antes de integrar

Conservar IDs, nombres, tipos, valores, eventos, `wire:*`, CSRF, campos ocultos y orden operativo. Confirmar el nivel de heading de card en contexto. Revisar contraste, foco, texto, movimiento reducido, reflujo y permisos sin usar CSS para sustituirlos. Ejecutar las pruebas del módulo y la regresión correspondiente. La base visual por sí sola no prueba ni modifica un flujo financiero.
