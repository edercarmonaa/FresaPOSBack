# UI-04 · Interfaces de autenticación

Se modernizan las pantallas existentes de acceso, cambio de contraseña, cuenta y administración de sesiones. El PIN, autorización de terminal y cambio de empleado conservan sus formularios y condiciones originales. La aprobación de UI-03 y autorización de UI-04 se registra en [ESTADO](ESTADO.md).

## Aplicación

Las cuatro vistas de página añaden `ui-auth-page` al contenido y solicitan `auth.css` mediante la pila Blade `styles`. El layout incorpora únicamente `@stack('styles')`. Las vistas de login y sesiones mantienen sus componentes Livewire; no se añade JavaScript, dependencia o endpoint.

`public/css/ui/auth.css` utiliza los tokens y botones existentes. Las tarjetas presentan jerarquía, separación y campos de al menos 44 px; la cuenta usa dos columnas desde 768 px y una en móvil. La pantalla de acceso permanece centrada con un ancho máximo de 640 px incluido su margen interior. El tema claro, oscuro y automático, movimiento, tamaño de texto y densidad proceden de las preferencias ya disponibles.

La tabla de accesos conserva sus seis columnas y acciones; incorpora caption, encabezados con `scope="col"`, una región de desplazamiento horizontal enfocable y un mensaje cuando no hay accesos. No oculta columnas por tamaño de pantalla. Se conservan las etiquetas UTC.

Los errores de login se vinculan al campo con `aria-describedby` y `aria-invalid`; se conservan el mensaje escapado, `role="alert"` y la desactivación existente durante el envío. Los resúmenes de error de contraseña y cuenta, los estados, validaciones nativas y campos protegidos permanecen disponibles.

## Contratos preservados

Se comparan las seis vistas anteriores y actuales con permisos concedidos y denegados. Se verifica igualdad de acciones y métodos, CSRF, campos, tipos efectivos, nombres, valores, autocompletado, límites, patrones, obligatoriedad, `wire:*`, confirmaciones y enlaces del contenido. Las condiciones `@can`, rutas, componentes y directivas CSRF también se comparan directamente con Git.

No se han modificado servicios, controladores, middleware, clases Livewire, permisos, rutas, configuración, modelos, migraciones, contratos API, conexión SQL, dependencias ni pruebas existentes. Los botones de formulario declaran explícitamente `submit`; las acciones Livewire fuera de formulario declaran `button`.

## Evidencias y límites

Véanse el [informe de entrega](entregas/UI-04.md), [procedimiento QA](qa/ui-04/README.md), [resultados](qa/ui-04/quality.json), [integridad](qa/ui-04/source-integrity.json) y [matriz responsive](qa/ui-04/responsive.csv).

Las comparativas «antes» renderizan el contenido anterior de Auth dentro del layout compartido de UI-03 que se conserva. Son reconstrucciones desde Git, con datos ficticios, no capturas de sesiones reales. Las vistas protegidas se revisan como fixtures con conexiones SQL eliminadas de su configuración; las operaciones se verifican mediante la regresión de integración existente en `fresapos_test`.

Acceso real: http://127.0.0.1:8080/login. Cuenta: http://127.0.0.1:8080/account después de autenticarse con un acceso existente autorizado. El servidor QA en http://127.0.0.1:8094/after-account.html no tiene sesión, autenticación ni operaciones; no sustituye la aplicación.

No se certifica WCAG AA completa con estos controles. Quedan sin ejecutar lectores de pantalla, dispositivos táctiles reales, Firefox/Safari, zoom nativo, auditoría automatizada completa, Lighthouse e INP real. No se verificaron Azure SQL ni cPanel en esta entrega.
