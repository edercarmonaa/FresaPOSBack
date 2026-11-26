# UI-01 — Propuesta visual de FresaPOS

26/11/2026 · **Pendiente de aceptación**. Propuesta separada de Laravel: [revisión local](http://127.0.0.1:8091), [instrucciones](prototipos/ui-01/README.md).

## Dirección visual

Identidad azul corporativa, navegación marino, superficies claras o azul profundo, tipografía sobria e iconos lineales. Se conserva el nombre FresaPOS. El glifo de ventana del prototipo identifica el espacio de trabajo; no sustituye un logotipo oficial. Las ilustraciones de productos son SVG propios de muestra, incorporados al HTML.

Administración prioriza lectura y búsqueda; POS prioriza productos y venta actual. La barra superior «PROTOTIPO UI-01» identifica siempre la maqueta y no forma parte de la futura aplicación. No se integró código en Blade, Livewire, rutas o public.

## Paleta propuesta

Los colores de referencia se ajustan en los roles de texto, acciones y bordes para mejorar contraste. Los separadores decorativos no se usan como único límite de campos.

| Rol | Claro | Oscuro |
|---|---|---|
| Fondo | #F3F5F9 | #0D1421 |
| Superficie | #FFFFFF | #151F30 |
| Superficie secundaria | #F8FAFC | #1B283B |
| Texto | #142137 | #EDF2FA |
| Texto secundario | #526178 | #B1BFD2 |
| Acción principal | #1D4ED8 | #A7C6FF |
| Texto sobre acción | #FFFFFF | #102448 |
| Selección / texto | #EAF1FF / #1E40AF | #233B63 / #BCD4FF |
| Borde de control | #7F8FA5 | #657B97 |
| Foco | #2563EB | #B6D1FF |
| Éxito / fondo | #166534 / #E9F7EF | #9BE3B5 / #15382B |
| Advertencia / fondo | #854D0E / #FFF5DF | #F2CF83 / #3F321A |
| Error / fondo | #B42338 / #FFF0F1 | #FFBAC8 / #41252E |

Primitivos conservados: marino #0F172A, azul corporativo #2563EB y azul claro #DBEAFE. Cada estado también usa texto o icono; el color no es su única señal. Los 40 contrastes semánticos medidos —10 pares, dos temas y dos navegadores— superan 4.5:1 para texto y 3:1 para borde/foco. Es una comprobación parcial, no una certificación de todas las combinaciones posibles.

## Tipografía y geometría

Inter variable local, peso 100–900 disponible; el diseño usa pesos moderados para jerarquía. Base 14 px, interlineado 1.5, títulos principales 2.15 rem en escritorio; números tabulares para importes. Preferencia de texto ampliado: factor 1.12. Alternativa: fuentes de sistema si la fuente local no carga.

Fuente original sin modificar: [repositorio Inter](https://github.com/rsms/inter), revisión 353b61b9f4430d5f420d56605a6e7993e0941470. Se incluyen [licencia SIL OFL 1.1](prototipos/ui-01/assets/fonts/OFL.txt) y [procedencia y SHA](prototipos/ui-01/assets/fonts/provenance.json). WOFF2: 352240 bytes. No hay proveedor de fuentes ni CDN en tiempo de ejecución.

Espaciado con ritmo de 4/8 px y ajustes ópticos, tarjetas de radio 12 px, campos de radio 7 px, bordes finos y sombras contenidas. Barra lateral de 232 px o 80 px colapsada; las superficies crecen con el ancho disponible. Controles activos de referencia: mínimo 44×44 px. La densidad altera el relleno de filas (10/16/22 px), conservando controles accesibles.

## Navegación y pantallas

| Prototipo | Pantalla existente de referencia | Propuesta |
|---|---|---|
| Reportes | `/reports`, `/reports/{report}` | Resumen operativo y accesos a reportes existentes |
| Productos | `/admin/catalog/products` | Tabla, filtros, estados, papelera y vista de formulario |
| Punto de venta | `/pos` | Catálogo y venta actual en columnas; resumen en diálogo en móvil |
| Acceso | `/login` | Formulario claro y panel de identidad en escritorio |
| Componentes | Solo documentación | Variantes de controles, mensajes y estados vacíos |

Esta selección demuestra patrones; no reemplaza el inventario de 12 familias de UI-00 ni elimina opciones de las pantallas existentes. La futura navegación deberá incluir exactamente los accesos autorizados por los guards y permisos actuales. `/admin` continúa siendo sesiones, no un CRUD nuevo de usuarios/roles. Los enlaces del prototipo son fragmentos locales, no rutas nuevas del producto.

El «dashboard» se limita a reorganizar el resumen de Reportes. Sus cifras se muestran como «—» y el gráfico está vacío: no se inventan KPI, ingresos, tendencias, inventario ni consultas nuevas. Cualquier representación futura exige datos que ya exponga el reporte, sin cálculos de negocio en JavaScript.

Productos y POS contienen seis artículos ficticios declarados como muestra. Importes, cantidades y total son cadenas estáticas con cuatro decimales, sin cálculos, solicitudes ni pagos. La ilustración no establece convenciones monetarias nuevas. Los botones de confirmación financiera/guardado están deshabilitados; los controles activos abren vistas previas o avisos explícitos.

## Estados y comportamiento visual

Claro, oscuro y automático siguen preferencias validadas. Automático escucha cambios del sistema. Densidad, texto, movimiento y menú lateral son preferencias visuales; localStorage contiene exclusivamente `theme`, `density`, `text`, `motion`, `sidebar` bajo `fresapos.ui01.visual.v1`. JSON inválido, claves ajenas o valores desconocidos se descartan. Si storage falla, funciona en memoria. Nunca almacena sesión, permisos, contraseñas, importes o ventas.

Se respeta `prefers-reduced-motion`, también ante cambio del sistema. Diálogos nativos con contención explícita de Tab, Escape y devolución de foco; pestañas con flechas/Home/End; regiones desplazables con etiqueta; campos con etiquetas y estados con texto. La preferencia de texto ampliado no equivale a una prueba de zoom real de navegador.

Las tablas mantienen estructura y cabeceras semánticas, con desplazamiento horizontal contenido en móvil y aviso visible. En POS el mismo resumen se traslada al diálogo móvil, sin duplicar IDs ni datos. El acceso es solo lectura; no admite credenciales ni inicia sesión. Formularios no se envían. CSP del prototipo bloquea conexiones y envíos; no modifica la política de seguridad de Laravel.

## Responsive y revisión

Chrome: cinco vistas × siete tamaños × dos temas. Tamaños: 2560×1440, 1920×1080, 1366×768, 1024×768, 768×1024, 390×844 y 320×844. Edge: cinco vistas × dos tamaños (1366×768, 390×844) × dos temas. Resultado y capturas en [QA](qa/ui-01/README.md).

Se revisaron visualmente muestras de escritorio/móvil, claro/oscuro, acceso, POS y resumen móvil. Sin errores de página/consola, solicitudes externas o envíos detectados. WCAG 2.2 AA sigue siendo objetivo: faltan lector de pantalla, dispositivos físicos, zoom real integral y evaluación completa de accesibilidad.

## Integración posterior

Estos estilos y componentes solo existen en documentación. UI-02 deberá convertir la propuesta aceptada en una base visual compatible con Blade/Livewire sin Node/Vite, sin alterar listeners, `wire:*`, validaciones, guards, rutas, contratos, impresión o cálculos. El prototipo no resuelve la dependencia CDN preexistente de la aplicación. No se ha autorizado ni iniciado esa integración.
