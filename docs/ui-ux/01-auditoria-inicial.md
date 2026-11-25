# UI-00 — Auditoría inicial y diagnóstico

Fecha de auditoría: **26 de noviembre de 2026**, America/Mexico_City. Estado propuesto: **pendiente de aceptación**. Alcance: inspección, inventario, línea base, evidencias y propuesta. No se inicia UI-01 ni se modifica código fuente.

## Punto de partida y método

Rama `main`, HEAD `1c750796f7d646e5d8c52cc49cb2890ee14b13da`. Git estaba limpio: sin archivos modificados, staged o sin seguimiento. El commit corresponde al cierre de 1.7a; no se reutilizó el antiguo HEAD de aquella investigación como estado actual.

Se registraron SHA-256 de los 2,539 archivos seguidos y de los diez archivos protegidos. Se inventarió el árbol completo por responsabilidad, se leyeron las 38 vistas Blade propias y las 14 clases Livewire para identificar su estado, métodos, guards y acoplamientos, y se inspeccionaron rutas, bootstrap, proveedores, configuración relevante, pruebas y documentación. No se afirma una revisión manual de cada binario histórico ni una auditoría exhaustiva de seguridad del backend.

No se encontró `AGENTS.md` en raíz, ancestros consultados ni en el inventario del proyecto. Se respetan README, CONTRIBUTING, SECURITY, arquitectura, frontend, hosting, entorno de pruebas y entregas anteriores. Las instrucciones actuales de PowerShell, no cambiar de rama y no tocar configuración prevalecen sobre ejemplos históricos Bash/Docker o la recomendación general de crear ramas en CONTRIBUTING.

## Arquitectura real

Monolito Laravel 13.42.0, PHP 8.3.36, `nwidart/laravel-modules` 13.3.0 y 13 módulos habilitados. SQL Server Express con autenticación integrada existente; las pruebas usan exclusivamente `sqlsrv_testing`/`fresapos_test`, con verificación de entorno y nombre efectivo antes del DDL. No se reconfiguró la conexión.

Blade renderiza la Web; Livewire 4.4.8 administra componentes e interacciones, con directivas Alpine en el POS. Sanctum 4.4.0 y Spatie Permission 8.5.0 existen en backend y quedan intactos. PHPUnit 12.5.43, Pint 1.32.2 y Larastan 3.14.0 nivel 5 forman la calidad actual. [Versiones extraídas del lock](qa/ui-00/versions.json).

El único layout principal propio es `resources/views/layouts/app.blade.php`. Integra navegación de Sales, Cash, Catalog, motivos y Reports, con condiciones de ruta/autorización. Auth, administración y operación comparten ese layout; POS cambia ancho y oculta parte de la navegación. No hay layouts especializados independientes todavía.

El layout carga `@tailwindcss/browser@4.1.18` desde jsDelivr y assets Livewire locales. No hay `package.json`, Vite o compilación frontend de aplicación; el hosting no requiere Node. Playwright 1.62.1 y Node se encontraron **ya disponibles en herramientas privadas de QA**, fuera de dependencias de la aplicación. No se instalaron. Los archivos Docker históricos se conservaron sin utilizarlos.

## Presentación y recursos

- Estilos distribuidos entre utilidades Tailwind y bloques inline propios de POS, Cash, correcciones y Reports. [Conteos y colores literales](qa/ui-00/inventory.json). No hay tokens semánticos centralizados ni hoja de estilos de aplicación en `public`.
- POS ya contiene catálogo/resumen lateral, objetivos táctiles, importes tabulares, imágenes lazy con dimensiones, fallback sin imagen, panel móvil con `x-trap.inert.noscroll`, Escape, foco y retorno explícito. Es una base que debe preservarse.
- Catalog ya ofrece formularios con errores asociados, listados con caption, filtros, paginación, imágenes y papelera. Reports adapta sus tablas y muestra indicadores/serie de datos reales.
- No hay selector de tema, persistencia visual centralizada, densidad de tablas ni tamaño de texto configurable. La preferencia oscura del sistema no cambia el fondo de login en la sonda actual.
- Tipografía de sistema; POS declara stack sans-serif. No se encontró Inter alojada localmente ni un paquete de fuentes del producto. No hay biblioteca de iconos frontend de aplicación: existen texto, signos y SVG de gráficas. `lucide` en herramientas privadas no constituye una biblioteca adoptada por el producto.
- No se encontró logotipo institucional versionado. `public/favicon.ico` está vacío. Esto no autoriza crear una marca nueva; debe preservarse cualquier recurso institucional que el responsable proporcione posteriormente.

## Funcionalidad existente y límites

El inventario distingue módulos de pantallas. `/admin` es administración de sesiones, no CRUD completo de usuarios, roles o permisos. `/` es una bienvenida técnica. No hay dashboard ejecutivo independiente; `/reports` sí tiene resumen, indicadores y serie reales, y nueve variantes de reporte. Un futuro UI-05 solo puede reorganizar esa información existente y sus destinos autorizados.

No se encontraron pantallas dedicadas de inventario, devoluciones/reembolsos, auditoría, notificaciones, dispositivos ni configuración general. Mesas se operan dentro del POS; no se encontró su mantenimiento Web independiente. No se crearán opciones para capacidades no implementadas.

Se inventariaron tickets para delimitar el alcance, pero `Modules/Tickets/resources/views/receipt.blade.php`, su error, `Modules/Reports/resources/views/pdf.blade.php`, generadores y exportaciones están excluidos de modificación. Tampoco se cambia la vista impresa al modernizar el layout web.

### Referencia histórica, separada de la evidencia actual

Se contrastaron [1.6f y su galería QA](../qa/1.6f/README.md) y el [cierre de 1.7a](../entrega-1.7a.md). Se revisaron como referencia el [POS histórico](../qa/1.6f/screens/01-pos-ticket-1440.png) y [Categorías móvil](../qa/1.6f/screens/03-categories-390.png). Muestran la organización operativa y el desplazamiento controlado de tablas ya existentes; no son capturas de UI-00 ni validación actual de estados autenticados. La galería de 1.6f advierte que algunas imágenes iniciales conservan rechazos de su primer recorrido: no se clasifican automáticamente como defectos actuales. No se copiaron, alteraron o reemplazaron esas evidencias.

## Clasificación y límites de autorización

| Nivel | Archivos/secciones | Tratamiento |
| --- | --- | --- |
| 1 | CSS puro de POS, envoltorios Blade y parciales exclusivamente visuales, futuros recursos/documentos UI | Solo presentación en una etapa futura aprobada; UI-00 mantiene todo código de solo lectura |
| 2 | Clases Livewire, vistas con scripts o preparación de datos, composer/lock y configuración de QA | Análisis individual y autorización expresa; las partes de negocio, permisos o seguridad no se modifican |
| 3 | Backend, rutas, migraciones, consultas, contratos, configuración operativa, pruebas funcionales e historia documental | Prohibido modificar durante el rediseño; tickets/PDF/XLSX excluidos |

La clasificación completa está en [file-classification.csv](qa/ui-00/file-classification.csv). En una plantilla de nivel 1 siguen siendo inmutables `wire:*`, `x-*` funcionales, IDs referenciados, `@can`, condiciones de permiso, `@disabled`, rutas, campos, CSRF, campos ocultos, validaciones, textos de seguridad y confirmaciones. La clasificación no autoriza editar automáticamente un archivo.

Archivos efectivamente autorizados para esta etapa: nuevos documentos y evidencias bajo `docs/ui-ux/`; auxiliares privados de captura/lectura en `.tools/`. Ningún archivo fuente existente, dependencia, configuración, prueba o documento histórico se modifica.

## Hallazgos y riesgos priorizados

| ID | Prioridad | Hallazgo comprobado o riesgo | Evidencia | Acción propuesta |
| --- | --- | --- | --- | --- |
| F01 | Alta | Dependencia de Tailwind CDN y procesamiento en navegador | Layout, docs/frontend.md, recursos de captura | Definir entrega CSS local en una etapa posterior expresamente aprobada; no cambiar build ahora |
| F02 | Alta | Scripts visuales y funcionales conviven | POS/Cash interceptan requests; Reports modifica campos enviados | Mantener interceptores y semántica; no sustituirlos por JS visual genérico |
| F03 | Alta | Caja y POS contienen restricciones financieras esenciales | Guards, estados pending, arqueo ciego, revisión y confirmación | Preservar orden, mensajes, campos y ocultación; regresión de permisos y estados |
| F04 | Media | Sin tres modos de apariencia ni preferencias visuales | Búsqueda de fuente y comparación light/dark de login | Proponer tokens y preferencias locales no sensibles; no implementarlos en UI-00 |
| F05 | Media | Navegación fragmentada en varios parciales | Layout e includes con permisos | Proponer navegación común sin nuevos destinos ni permisos |
| F06 | Media | CSS literal/inline y estilos globales | POS, Cash y Reports; `main` y selectores de foco globales | Aislar por layout y centralizar tokens; riesgo de cascada, no defecto demostrado en todas las pantallas |
| F07 | Media | Login: campos 42 px, botón 40 px de alto | Geometría actual a 390 px | Objetivo futuro 44 px. No equivale por sí solo a incumplimiento de WCAG AA |
| F08 | Media | Estética básica y espacios sin uso en login | Capturas 1366/390 y página de bienvenida | Prototipo de autenticación y shell administrativo, sin cambiar flujo |
| F09 | Media | Foco y tablas no siguen un estándar único | Login usa outline nativo; Sessions carece de caption propio; otros módulos sí lo tienen | Verificación manual ampliada y patrón accesible común |
| F10 | Media | No hay dashboard ejecutivo independiente | Vistas y rutas reales | UI-05 limitado al resumen existente; excluir nuevos indicadores y consultas |
| F11 | Media | Cobertura visual actual de pantallas protegidas pendiente | No se usaron credenciales ni fixtures operativos | Preparar QA autenticada aislada y secuencial antes de intervenir esas vistas |
| F12 | Media | Rendimiento medido en muestra pequeña y host con PHPUnit activo | Cinco recargas warm de login | Comparar después bajo condiciones equivalentes; no inferir rendimiento POS o de campo |
| F13 | Baja | Sin Inter ni iconografía central/local de producto | Inventario de assets y estilos | Validar licencia de Inter y SVG propios en UI-01/02; sin CDN obligatorio |
| F14 | Observación | Firefox/WebKit no disponibles; Safari no verificado | Sonda de ejecutables existentes | No declarar compatibilidad; acordar entorno en auditoría final |

No se encontraron ni corrigieron defectos funcionales como parte de UI-00. La intermitencia histórica de 1.7a permanece como riesgo documentado del entorno; no se reabre ni se alteran sus pruebas.

## Accesibilidad y responsive: alcance real

Objetivo futuro: [WCAG 2.2 AA](https://www.w3.org/TR/WCAG22/), con comprobación manual y automatizada. Las capturas/sondas actuales cubren inicio y login; no demuestran conformidad global. Login presenta `lang=es`, etiquetas asociadas, orden de foco usuario → contraseña → Entrar y foco nativo visible. El contraste medido de etiquetas es aproximadamente 17.04:1 y del botón 17.83:1 en fondo sólido de esa pantalla. No se midieron todos los estados, bordes, errores ni pantallas protegidas.

Chrome se revisó a 2560×1440, 1920×1080, 1366×768, 1024×768, 768×1024, 390×844 y 320×844; Edge a 1366×768 y 390×844. Sin desbordamiento horizontal en esas dos páginas. No se validó zoom real al 200/400 %, lector de pantalla, interacción táctil física, navegación completa protegida o adaptación de todas las tablas. El viewport de 320 px no se presenta como sustituto de todas esas verificaciones.

## Propuesta de Design System compatible — no implementada

Mantener Blade, Livewire y el comportamiento Alpine existente. Proponer CSS propio con variables y capas: primitivos → semánticos por tema → componentes. Separar nombres como `--color-blue-600`, `--action-primary-bg`, `--button-primary-bg`; usar escala 4/8 px, radios moderados, tipografía tabular para dinero y z-index documentado. Probar cada combinación semántica de contraste; los colores de referencia del encargo no garantizan por sí solos contraste adecuado sobre blanco.

Estructura propuesta, **todavía no creada**:

```text
public/css/ui/tokens.css          # primitivos y temas semánticos
public/css/ui/components.css      # botones, campos, alertas, tablas
public/css/ui/layouts.css         # administración, POS, autenticación
public/js/ui/preferences.js       # solo tema/densidad/texto/movimiento/menú
resources/views/components/ui/    # componentes Blade de presentación
docs/ui-ux/prototipos/            # prototipos separados de las rutas funcionales
```

La organización exacta se decidirá tras UI-01; no obliga a reorganizar módulos. Mantener wrappers de cada módulo y extraer únicamente presentación aprobada. No mezclar estilos de impresión en el tema web. No adoptar otro framework, bibliotecas duplicadas o iconos remotos. Usar Inter local solo con archivo/licencia autorizados; stack de sistema como respaldo. Preferencias: valores enumerados y validados, try/catch ante storage no disponible, default automático, respeto a preferencias del SO y script inicial mínimo para evitar flash. No guardar datos personales, financieros, credenciales, permisos ni sesión.

La eventual eliminación del CDN requiere su propio análisis de clases dinámicas, assets y autorización de nivel 2 si afecta configuración/herramientas. No se instala CLI, Node, Vite o paquetes como consecuencia automática de esta propuesta.

## Plan detallado por etapas — todas las siguientes requieren aprobación

| Etapa | Alcance propuesto y archivos candidatos | Verificación/condición de salida |
| --- | --- | --- |
| UI-01 | Solo propuesta visual y prototipos separados en docs/ui-ux/prototipos: auth, navegación, tablas/formularios, Reports existente y POS | Revisar dirección azul, claro/oscuro/auto y variantes responsive. Sin integrar en app ni mostrar KPI ficticios funcionales |
| UI-02 | Tokens, CSS y componentes visuales nuevos, catálogo separado | Contraste y estados; decisión expresa sobre CDN y cualquier herramienta; no tocar backend |
| UI-03 | Layout app y parciales de navegación de Sales/Cash/Catalog/Reports; JS visual de preferencias | Destinos y guards idénticos; tolerancia a storage inválido, foco, menú móvil, no flash |
| UI-04 | Login, contraseña, cuenta y sesiones: secciones visuales de Auth | No modificar autenticación/PIN/terminal; pruebas de sesión, CSRF, mensajes y permisos; revisar foco y etiquetas |
| UI-05 | Resumen real en Reports; bienvenida solo con contenido/destinos ya existentes | Sin nuevas consultas, indicadores ni gráficas ficticias. Si requiere backend, excluir y pedir autorización independiente |
| UI-06 | Patrones visuales comunes de tablas, formularios, alertas y paneles | Preservar bindings/IDs/confirmaciones; teclado, mensajes y estados; no cambiar paginación/filtros |
| UI-07.1 | Categorías y productos: vistas de Catalog | Formularios, imagen, errores, filtros y papelera con QA aislada |
| UI-07.2 | Motivos y administración de sesiones existentes | No añadir CRUD de usuarios/roles; permisos y confirmaciones intactos |
| UI-07.3 | Reportes y errores propios web | Nueve tipos y estados; filtros/tabla/serie sin cambiar scripts funcionales. PDF/XLSX excluidos |
| UI-08.1 | Catálogo, mesas y resumen POS | Preservar panel móvil/foco, selección, cantidades/notas, atajos y estados |
| UI-08.2 | Cobro, descuentos, operaciones y correcciones: apariencia exclusivamente | Revisión más estricta de campos/orden/estado pending/confirmaciones; ninguna operación real; suite financiera y QA aislada |
| UI-08.3 | Caja, movimientos, arqueo e historial | Preservar ceguera del conteo y permisos; pruebas financieras completas sin cambiar aserciones |
| UI-09 | Revisión integral responsive/a11y | Todos los tamaños/temas; teclado, AT, zoom, foco y contraste; navegadores disponibles claramente identificados |
| UI-10 | Optimizar solo assets/CSS/JS visual | Comparación equivalente LCP/CLS, interacción de laboratorio; Lighthouse/axe solo tras aprobación si requieren herramientas nuevas |
| UI-11 | Auditoría final y matriz completa | Regresión funcional/visual, límites y seguridad, integridad de código/datos, aceptación del responsable |

Antes de cada etapa: inventario Git nuevo, lista concreta de archivos, revisión de diferencias y línea base aplicable. No trasladar automáticamente esta autorización a etapas siguientes. Antes de integrar cambios en vistas protegidas debe existir QA autenticada aislada, sin modificar fresapos; no compartir fresapos_test entre runners.

## Criterios de salida de UI-00

Inventario y clasificación verificables; versiones y riesgos documentados; resultados reales de calidad y suite; evidencias visuales con límites; propuesta compatible; prueba de preservación de fuentes y protegidos; ningún rediseño, instalación o DML operativo. La auditoría puede presentarse con verificaciones pendientes explícitas según el apartado 37 del encargo. **La aprobación definitiva y el comienzo de UI-01 requieren decisión expresa del responsable.**
