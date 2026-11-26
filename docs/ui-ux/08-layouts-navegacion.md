# UI-03 — Layouts, navegación y preferencias

26/11/2026 · Pendiente de aceptación. UI-02 fue aceptada por el responsable; esta entrega integra su base visual en el layout existente, sin cambiar rutas, controles de acceso o formularios.

## Estructura implementada

`resources/views/layouts/app.blade.php` conserva CSRF, Livewire, el CDN existente y los yields content/mainClass. Carga los activos locales UI y renderiza navegación una sola vez, con sus permisos originales. Los parciales nuevos están en `resources/views/layouts/partials/`: header, navigation y preferences. El layout no obtiene indicadores ni calcula información de negocio.

| Contexto | Selección | Distribución |
|---|---|---|
| Administración / inicio | Resto de rutas que usan el layout | Cabecera y menú lateral cuando hay enlaces autorizados; contenido amplio |
| POS | Nombre existente sales.pos | Cabecera y franja operativa horizontal en escritorio; sin menú lateral que reduzca el área de trabajo |
| Acceso | login y auth.password | Contenido centrado; los formularios internos permanecen intactos |
| Móvil | Viewport hasta 1023 px | Mismo nodo de navegación en panel modal lateral; sin duplicar enlaces o IDs |

Sin enlaces autorizados no se renderiza un panel vacío ni un botón de menú inactivo. La cabecera contiene identidad, selector de tema y preferencias visuales. Mi cuenta y Administración mantienen sus destinos y condiciones originales dentro de la navegación. No se copiaron formularios de cierre de sesión: siguen en sus pantallas actuales, con sus tokens y controles.

## Guardias y destinos conservados

| Grupo | Condición original, sin cambios | Destinos |
|---|---|---|
| Punto de venta | auth web + sales.view + catalog.products.view | sales.pos, auth.account; auth.admin además requiere users.view |
| Caja | auth web + cash.view | cash.desk |
| Catálogo | auth web + categories.manage o products.view junto a create/update/deactivate/restore | Productos según view + acción; categorías según manage; Mi cuenta dentro del grupo original |
| Motivos | auth web + settings.update + sales.view | sales.reasons |
| Reportes | reports.view, tal como estaba | reports.index |

Los permisos de Catálogo llevan el prefijo `catalog.` completo en código. En POS se siguen excluyendo los grupos Catálogo, Motivos y Reportes, igual que en el layout anterior. Se conservan los enlaces duplicados a Mi cuenta cuando dos grupos originales están disponibles; deduplicarlos podría alterar la visibilidad por permisos y no se hizo en esta etapa. Administración continúa siendo sesiones, no un CRUD nuevo de usuarios/roles.

Las pruebas comparan href y texto de todos los enlaces anteriores/nuevos para doce combinaciones de permisos, en administración, POS y contraseña: 36 comparaciones. Usan una identidad y Gate en memoria, sin consultas o escritura SQL. Las fixtures de referencia son copias del HEAD anterior y no se consumen en producción. Otra comprobación conserva las directivas de acceso de los cinco parciales.

## Integración CSS

`public/css/ui/layouts.css` implementa estructura, cabecera, paneles, foco y un puente de colores para clases existentes. No cambia su visibilidad, sus bindings ni datos. Los controles de formulario conservan sus atributos y alcanzan una altura mínima visual de 44 px; checkbox/radio/hidden se excluyen del ajuste de campo.

El orden de capas se declara en tokens.css: theme → base → components → utilities → ui.tokens → ui.base → ui.components. Al integrar se comprobó que el preflight de Tailwind podía anular componentes si sus capas quedaban al final. El orden explícito conserva la base UI; no reemplaza Tailwind ni elimina el CDN. Evitar utilidades de colores o padding contradictorias en nuevos componentes UI; usar sus variantes.

El marco acepta las vistas actuales con mainClass, pero controla ancho/padding externos. Los módulos no han recibido todavía el rediseño interno de UI-04/UI-07/UI-08. En especial, el contenido POS conserva su CSS propio: una franja oscura del layout no significa que ya exista un POS completo oscuro. Su adaptación detallada corresponde a UI-08 y debe preservar cobro, arqueo y confirmaciones.

## Preferencias locales

`public/js/ui/theme.js` mantiene FresaUITheme y agrega FresaUIPreferences. Se carga antes del CSS. Solo usa `fresapos.ui.visual.v1` con cinco valores validados:

| Clave | Valores | Predeterminado |
|---|---|---|
| theme | auto / light / dark | auto |
| density | standard / compact / comfortable | standard |
| text | normal / large | normal |
| motion | auto / reduce | auto |
| sidebar | expanded / collapsed | expanded |

Una entrada antigua que solo tenga theme recibe los otros predeterminados. Se descartan JSON corrupto, campos ajenos y valores desconocidos; storage bloqueado funciona en memoria. No almacena tokens, PIN, permisos, sesión, información personal o financiera. No usa backend, nuevas tablas o endpoints. No migra la clave documental del prototipo UI-01.

Automático escucha el tema del SO; movimiento reducido del SO siempre prevalece. Texto ampliado escala las unidades relativas (1.12), sin sustituir una prueba de zoom de navegador. Densidad afecta las tablas que adopten los componentes UI; no reprograma los listados actuales. El menú colapsado solo reduce la barra lateral administrativa en escritorio; POS y acceso mantienen su distribución especializada.

FresaUITheme.get/set/reset conserva su interfaz de tema. FresaUIPreferences.get() devuelve una copia de los cinco valores; set(nombre,valor) valida la lista; reset() restaura todos. El evento ui:preferences es exclusivamente visual. No existe sincronización entre pestañas ni persistencia de cuenta en esta entrega.

## Teclado y foco

Saltar al contenido apunta a main enfocable. El menú móvil mantiene el mismo árbol de enlaces, con rol dialog/aria-modal mientras está abierto, fondo de cierre, Tab contenido, Escape y retorno al disparador. Header y main se vuelven inert temporalmente; al cerrar se restaura el valor anterior y al cambiar a escritorio se libera el estado propio del panel.

Preferencias usa dialog nativo, etiquetas explícitas, Tab contenido, Escape y devolución de foco. Sus eventos están limitados a atributos data-ui propios; no interceptan formularios, enlaces de navegación, operaciones Livewire o atajos financieros. Se conserva Livewire en el layout. No se reemplazan modales ni confirmaciones operativas existentes.

## Revisión segura

La aplicación real sigue en [8080](http://127.0.0.1:8080); [login real](http://127.0.0.1:8080/login). Para observar el marco con todos los grupos sin usar una sesión o datos operativos: [fixture administrativa](http://127.0.0.1:8093/admin.html) [fixture POS](http://127.0.0.1:8093/pos.html) y [fixture de marco Reportes](http://127.0.0.1:8093/reports.html). Están rotuladas como QA; no son rutas de Laravel ni endpoints funcionales. Sus tokens son marcadores inertes, no credenciales.

Regeneración/servidor, PowerShell desde raíz:

```powershell
.\scripts\local.cmd php docs/ui-ux/qa/ui-03/export-layouts.php
.\.tools\php\php.exe -S 127.0.0.1:8093 -t docs/ui-ux/qa/ui-03/fixtures docs/ui-ux/qa/ui-03/router.php
```

El exportador renderiza el layout real con Gate y usuario en memoria. El servidor posterior solo lee una lista cerrada de recursos estáticos y GET; las demás solicitudes reciben 404. No se debe navegar a enlaces de negocio dentro de esas fixtures: solo demuestran presentación/visibilidad y foco. E2E financiero autenticado de la nueva apariencia no se confunde con esta evidencia.

Ver [QA](qa/ui-03/README.md), [trazabilidad](qa/ui-03/traceability.csv), [autorización excepcional](qa/ui-03/authorization.md) e [informe](entregas/UI-03.md). Las evidencias anteriores se conservan; los recursos productivos evolucionan bajo esta autorización y sus hashes se registran nuevamente en UI-03.
