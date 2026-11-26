# UI-06 · Componentes administrativos compartidos

27/11/2026. Extensión optativa del Design System existente. UI-05 aceptada al autorizar UI-06. La integración individual en módulos corresponde a UI-07; esta entrega no modifica sus vistas ni flujos.

## Inventario y responsabilidades

| Componente | Responsabilidad | Datos que debe proporcionar el consumidor |
|---|---|---|
| `ui.select` | Select nativo, variantes de error y atributos | Nombre, opciones, valor seleccionado, bindings, validación y etiqueta |
| `ui.textarea` | Área de texto nativa | Nombre, contenido escapado, límites, bindings y etiqueta |
| `ui.filters` | Contenedor adaptable de campos y acciones | Formulario original, campos y slot `actions`; no ejecuta consultas |
| `ui.pagination` | Región etiquetada con resumen y acciones | Slot `summary`, botones/enlaces originales y sus estados; no calcula páginas |
| `ui.dialog` | Modal o panel lateral mediante `variant="drawer"` | ID único, título, descripción opcional, cuerpo y footer; ninguna autorización |
| `ui.notification` | Mensaje persistente, opcionalmente flotante y cerrable | Mensaje real, tono y decisión explícita de anuncio/cierre |
| `ui.state` | Vacío, carga o error proporcionado por el consumidor | Título, descripción y estado real; no inicia solicitudes ni temporizadores |
| `ui.admin-assets` | Registro único de CSS y JS optativos | Layout con pilas `styles` y `scripts` |

Se reutilizan sin cambios `ui.table`, `ui.field`, `ui.input`, `ui.button`, `ui.alert`, `ui.badge`, `ui.icon` y `ui.card`. No se duplica la tabla ni se sustituye su caption o región desplazable. Se mantienen densidad, fuente y temas existentes.

## Recursos y adopción

`admin.css` y `admin.js` se registran una sola vez por render mediante `@once` y pilas Blade. El layout existente solo añade `@stack('scripts')` al final. No carga estos archivos en páginas que no usan los componentes optativos; la prueba del inicio lo comprueba. Los select y textarea aprovechan el CSS base ya existente.

El layout consumidor debe renderizar sus secciones antes de emitir las pilas, como hace `@extends` con `@section`. No insertar el componente después de haber emitido manualmente la pila de cabecera. El catálogo utiliza su propio layout Blade con ese mismo mecanismo.

```blade
<x-ui.field input-id="existing-state" label="Estado">
    <x-ui.select id="existing-state" name="state" wire:model.live="stateFilter">
        {{-- Conservar las opciones y @selected originales. --}}
    </x-ui.select>
</x-ui.field>
```

Conservar el formulario original, método, action, `@csrf`, campos ocultos, nombres, IDs, defaults, límites, `wire:*`, condiciones de permisos, validaciones y confirmaciones. Los componentes no generan CSRF ni formularios automáticamente. No trasladar operaciones financieras o validación al JavaScript visual.

## Modal y panel lateral

```blade
<x-ui.button data-ui-dialog-open="example-dialog">Abrir detalle</x-ui.button>
<x-ui.dialog id="example-dialog" title="Detalle" description="Descripción del contenido">
    {{-- Contenido y controles ya existentes. --}}
    <x-slot:footer>
        <x-ui.button variant="secondary" data-ui-dialog-close>Volver</x-ui.button>
    </x-slot:footer>
</x-ui.dialog>
```

Usar IDs únicos, sin duplicar IDs entre instancias, e indicar `variant="drawer"` para panel lateral. Los disparadores y cierres deben ser botones de tipo `button`; los submit se ignoran. El diálogo usa `showModal`, título asociado y descripción solo si existe. Escape o cierre explícito regresan al disparador visible. Tab/Shift+Tab se contienen únicamente en diálogos marcados de UI-06; el navegador aplica la modalidad e inercia. No se cierra al pulsar el fondo. Si otro diálogo o el menú móvil están abiertos, no se abre un segundo.

El controlador delega eventos y es idempotente; no requiere registrar cada nodo tras un render Livewire. No sustituye `wire:confirm`, PIN, confirmaciones de pago, permisos o flujos existentes. Su integración posterior debe conservar el mecanismo funcional de cada módulo.

## Mensajes y estados

Notificaciones persistentes por defecto: `dismissible=false`, `floating=false`, `live="off"`. `live="polite"` o `assertive` se elige explícitamente para mensajes dinámicos reales; no añadir anuncios a mensajes estáticos sin necesidad. No hay autodescarte. `dismissible=true` muestra un cierre exclusivamente visual; el consumidor debe proporcionar `return-focus` con el ID de un control visible y habilitado, compatible con el contexto modal si existe. La alternativa de foco es `ui-main`.

`kind="loading"` muestra un skeleton estático decorativo y `aria-busy=true`. No simula solicitudes ni éxito. Usarlo solo con un estado real del consumidor; los tres estados simultáneos del catálogo están rotulados como muestras. Error y vacío no implementan reintentos: las acciones llegan mediante slots.

## Seguridad e integración futura

Texto de props y expresiones Blade escapado. El consumidor conserva escape y validación de sus slots. Nunca enviar HTML sin confianza. Los IDs se resuelven con `getElementById`, sin evaluación de código ni selectores derivados. El script no usa fetch, almacenamiento, rutas de negocio, importes ni credenciales; las preferencias siguen a cargo del controlador existente.

Se preservan ocho componentes previos y todos los módulos. [Catálogo](catalogo/ui-06/README.md), [QA](qa/ui-06/README.md), [informe](entregas/UI-06.md). Los documentos históricos permanecen como registro de sus etapas.
