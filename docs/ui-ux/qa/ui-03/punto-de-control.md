# UI-03 — Punto de control previo a integración

26/11/2026. **En preparación; no entregada ni aceptada.** El responsable aceptó UI-02 y autorizó continuar con UI-03 mediante «adelanyte continua».

La inspección y línea base están completas. No se ha modificado código de la aplicación ni prueba alguna. Este punto de control contiene una excepción concreta pendiente; no representa una nueva petición de autorización general para UI-03.

## Conflicto encontrado

La última prueba de `tests/Feature/DesignSystemRenderingTest.php`, añadida en UI-02, fija su aislamiento temporal: el inicio no puede cargar `/css/ui/tokens.css` ni `/js/ui/theme.js`. UI-03 consiste precisamente en cargarlos en los layouts reales. Mantener esa condición y activar el sistema son requisitos incompatibles.

El apartado 6 del encargo incluye «Pruebas funcionales existentes» entre modificaciones prohibidas y ordena «detener la intervención correspondiente, documentar el impedimento y solicitar autorización independiente» cuando es necesario modificar un elemento protegido. No se asumió que aprobar UI-03 derogara esa restricción específica.

Se propone [este cambio exacto](required-test-adjustment.patch), todavía **no aplicado**: sustituir únicamente la última prueba por una que exija los cinco recursos locales y la activación `ui-root`. Las cinco pruebas restantes de ese archivo y todas las demás pruebas existentes permanecerán intactas. Se agregarán pruebas de guardias/destinos, layout contextual y renderizado sin alterar contratos.

La propuesta es una actualización de la condición de etapa, no eliminar el control ni hacer pasar una suite omitiéndolo. No se utilizarán URLs alternativas, inclusión indirecta o detección del entorno de pruebas para esquivar la aserción actual.

## Plan delimitado

| Acción | Archivos / responsabilidad |
|---|---|
| Modificar presentación | `resources/views/layouts/app.blade.php`; parciales navigation de Sales, Cash, Catalog, Reports y reasons-navigation de Sales |
| Extender preferencias visuales | `public/js/ui/theme.js`, preservando API de tema; cinco preferencias permitidas, defaults/validación/storage bloqueado/movimiento reducido |
| Crear | `public/css/ui/layouts.css`, `public/js/ui/layout.js`, parciales compartidos para cabecera, navegación y preferencias; nuevas pruebas de UI y documentación/evidencias UI-03 |
| Excepción pendiente | Solo la última prueba de DesignSystemRenderingTest, según el patch adjunto |
| No tocar | Rutas, controladores, Livewire PHP, servicios, modelos, migraciones, cálculos, seguridad, conexiones, composer/lock, pruebas restantes, impresión y exportaciones |

Se conservarán exactamente las condiciones actuales: POS requiere sales.view y catalog.products.view; Caja cash.view; Catálogo su condición compuesta y permisos de acciones/categorías; Motivos settings.update y sales.view; Reportes reports.view; Administración users.view donde ya está disponible. La exclusión contextual de Catálogo/Motivos/Reportes en POS se mantendrá. Se conservarán destinos y acceso a Mi cuenta; no se inventará CRUD de usuarios o roles.

El layout distinguirá contexto administrativo, POS y autenticación. Los formularios, bindings y vistas internas de módulos se modernizan en sus etapas posteriores. Se conservarán CSRF, Livewire y CDN preexistente: eliminar la dependencia CDN no forma parte de esta excepción.

## Línea base ejecutada

267 pruebas / 1085 aserciones, cero errores/fallos/omitidas; Pint, Larastan nivel 5, Composer validate --strict y check-platform-reqs: salida 0. [Resultados de comandos](baseline-results.jsonl), [JUnit](baseline.xml), [consola](baseline-test.log). No se ejecutó integración SQL completa: esta fase todavía es inspección y no cambia comportamiento o datos.

Comandos PowerShell desde raíz:

```powershell
.\scripts\local.cmd composer test -- --testsuite Feature,Architecture,Modules --log-junit .tools/ui03-baseline.xml
.\scripts\local.cmd composer lint
.\scripts\local.cmd composer analyse
.\scripts\local.cmd composer validate --strict
.\scripts\local.cmd composer check-platform-reqs
```

Tras recibir la excepción se implementará la etapa, se ejecutará regresión comparada y se hará QA segura de temas, almacenamiento, teclado, foco y responsive. Sin commits, push, cambio de rama, despliegue o DML operativo. Si no se autoriza la excepción, no se activará el sistema en el layout.
