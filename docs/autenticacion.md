# Autenticación Web y API — 1.3b/1.3c

La autenticación vive en Auth; identidad, estados y contraseñas siguen en Users. Auth consume el contrato público IdentityAccess y el DTO UserSnapshot. La matriz permite Auth → Users/Core y no la relación inversa. El proveedor nativo de Laravel se adapta en Users para rechazar identidades inactivas o eliminadas; Spatie conserva el guard canónico web y los 44 permisos existentes.

## Primer administrador

El comando existente se reutiliza, sin crear otro alias ni cuentas automáticas:

```powershell
.\scripts\local.cmd php artisan users:provision-admin
```

Ejecutarlo manualmente en una consola privada con entrada oculta. Solicita nombre, username, contraseña y confirmación; no admite claves por argumentos ni entrada visible como fallback. Comprueba que no existen usuarios, incluyendo eliminados, y que el rol admin conserva los permisos administrativos requeridos. No repara ni sobreescribe una cuenta existente. Se verificó el flujo del comando en fresapos_test sustituyendo solo el dispositivo de preguntas y comprobando que las dos preguntas de contraseña son ocultas y sin fallback; no se simuló una consola física de Windows ni se eligieron credenciales operativas.

La primera cuenta queda con cambio obligatorio. Abrir `/login`, autenticarse y elegir una contraseña distinta en `/auth/password`. Se aplica la política e historial de las últimas tres claves de 1.3a. No se requiere email. No hay recuperación de cuentas ni reset administrativo en esta entrega.

## Web

`GET /login` muestra Blade y el componente Livewire auth.login. El formulario tiene fallback POST /login, CSRF y autocomplete apropiado; no persiste el campo password después de la acción de Livewire. Los errores de validación nuevos usan diccionarios propios es/en, sin alterar el traductor global ni las validaciones anteriores.

Después de verificar username/contraseña se regenera la sesión destruyendo el ID anterior y se renueva CSRF. `/account` es la pantalla privada mínima: identidad activa, PIN, contexto de terminal y accesos propios recientes. `/admin` requiere users.view en el servidor; no es una administración CRUD completa. Los controles visuales no sustituyen al Gate.

POST /logout revoca el registro actual, cierra el guard, invalida la sesión y regenera CSRF. Un ID de sesión antiguo no recupera el acceso. En cada petición privada se comprueba el proveedor activo, el registro de acceso y su vigencia; no se confía solo en el contenido de la cookie ni en JavaScript. Un usuario con cambio obligatorio solo puede cambiar su contraseña o salir. Tras cambiarla se revocan accesos anteriores y se crea un nuevo contexto Web.

El timeout predeterminado es **30 minutos de inactividad**, compartido entre pestañas mediante la misma sesión y fila de acceso. La actividad válida renueva su vigencia. Los fallos visuales de Livewire no llaman a logout. Un formulario antiguo puede requerir recarga por CSRF tras regenerar la sesión; ese error no implica cerrar automáticamente una sesión vigente.

## API y tokens

Se instaló Laravel Sanctum 4.4.0. La API usa exclusivamente Authorization: Bearer; las cookies Web no autentican la API y no se habilitó el flujo SPA ni la ruta auxiliar de cookie CSRF de Sanctum. No se cambió CORS.

| Método y ruta | Datos / comportamiento |
| --- | --- |
| POST /api/v1/auth/login | username, password y device_name opcional. Devuelve token, token_type, expires_at y el snapshot propio. |
| GET /api/v1/auth/me | Token válido; devuelve identidad propia, roles y permisos actuales. |
| POST /api/v1/auth/logout | Revoca exclusivamente el token bearer presentado y conserva otros accesos. |
| POST /api/v1/auth/password | current_password, password y password_confirmation. Cambia la clave propia, revoca accesos anteriores y emite un token nuevo. |

El endpoint de contraseña es necesario para completar el acceso de cuentas creadas con cambio obligatorio; no implementa restablecimiento administrativo. Los tokens iniciales de esas cuentas tienen solo la capacidad password:change. Me, logout y password permanecen disponibles; otros endpoints protegidos requieren completar el cambio y la capacidad access. Las capacidades técnicas del token nunca sustituyen a los permisos de Spatie.

Los tokens se generan mediante HasApiTokens/createToken nativos. Solo se guarda su SHA-256; el valor completo se devuelve una vez después de verificar credenciales, en el cuerpo JSON y con Cache-Control no-store. No va en URLs, sesiones Web, logs ni listados de acceso. Cada token tiene una fecha de expiración fija, **ocho horas por defecto**, y la actividad no la extiende. Al vencer se requiere autenticación con contraseña; no se implementan refresh tokens.

El bloqueo local por PIN de una futura pantalla Android es independiente de la vigencia del token en el servidor. No renueva un token ni reemplaza el login. El cliente futuro deberá proteger el token con almacenamiento seguro del sistema; no se implementa Android ni aprobación de Devices ahora. device_name es una etiqueta, no aprobación de un dispositivo.

Los sobres JSON de 1.2c, sus booleanos y X-Request-Id/meta.request_id se conservan. Los cuatro endpoints están en el archivo modular, bajo el prefijo único de la raíz, con nombres api.v1.auth.*. Ping sigue público y no consulta tablas.

| Condición | Código / estado público |
| --- | --- |
| Credenciales incorrectas, usuario desconocido, inactivo o eliminado durante login | INVALID_CREDENTIALS / 401, mismo mensaje. |
| Falta de token o token inválido, vencido o revocado | UNAUTHENTICATED / 401; no se revela el motivo interno. |
| Registro de acceso no vigente o inconsistente | SESSION_EXPIRED / 401 en el manejador JSON. |
| Falta de un permiso | FORBIDDEN / 403. |
| Cambio obligatorio pendiente | PASSWORD_CHANGE_REQUIRED / 403 fuera de las rutas de recuperación. |
| Validación de campos | VALIDATION_ERROR / 422. |
| Exceso de intentos o de accesos simultáneos | TOO_MANY_REQUESTS / 429. |

PIN_INVALID identifica la excepción del servicio cuando llega al manejador JSON; el flujo Web mínimo la transforma en un error de formulario genérico. No se expuso un endpoint público de login con PIN. Un cliente debe tratar cualquier 401 de un recurso protegido como pérdida de acceso, sin deducir existencia o estado de una cuenta.

## Registro de accesos y cierre

Laravel conserva el payload Web en su driver existente, file en este equipo. Sanctum conserva sus tokens. auth_access_sessions añade **solo metadatos y revocación**, sin duplicar payloads ni guardar identificadores reutilizables: usuario, canal web/api, método password/pin, creación, actividad, expiración, revocación y referencia segura al contexto.

El ID Web se almacena únicamente como SHA-256, nunca se devuelve. La referencia a token es una FK y se oculta en listados. El ID público del registro es un ULID independiente que no autentica. Tras borrar un token se conserva su registro revocado mediante FK SET NULL; todas las referencias a users son NO ACTION.

SessionRegistry permite consultar accesos propios y cerrar uno. Para cerrar accesos ajenos exige la capacidad existente users.status y un actor activo con cambio de clave completado; no se creó otro permiso ni un bypass por rol. El componente auth.sessions permite consultar accesos propios y ajenos autorizados, cerrar uno, cerrar los demás propios o todos los del usuario consultado, con confirmación previa. El cierre Web impide la siguiente petición aun cuando el payload físico aún no haya sido recogido por el driver.

La emisión se coordina transaccionalmente en SQL mediante IdentityAccess::lock y la coordinación SQL existente de Users, incluso sin registros de sesión y límites configurables. Valores predeterminados: cinco accesos Web y cinco API. Un nuevo login Web en el mismo contexto retira el registro anterior. Se rechaza el exceso sin sustituir silenciosamente otro dispositivo.

Users emite UserAccessChanged dentro de la transacción de activación/desactivación, borrado lógico y cambio de contraseña. Auth invalida accesos y tokens sincrónicamente; desactivar/eliminar también elimina el PIN e invalida contextos de terminal asociados. La auditoría se persiste en esa misma transacción: un fallo revierte la operación. El proveedor y los middleware conservan la comprobación de estado en cada petición.

## PIN y cambio rápido

Un empleado activo con cambio obligatorio completado puede registrar, cambiar o deshabilitar **su propio PIN**, confirmando su contraseña. Se permiten de cuatro a seis dígitos como string para preservar ceros iniciales. auth_employee_pins guarda un hash de Laravel, oculto en serialización. La contraseña, el PIN y las confirmaciones se excluyen también del flash de formularios.

Para habilitar una terminal Web se requiere una sesión completa vigente, permiso cash.open y nueva verificación de contraseña. Esto autoriza el contexto de acceso; **no abre caja ni registra una terminal de Devices**. El servidor conserva terminal_owner_id, no lo toma del cliente.

El empleado entrante debe tener PIN válido, estar activo, haber completado su cambio de contraseña y tener sales.create o cash.open. Se rechaza el acceso por PIN a identidades con capacidades administrativas users.* o roles.*, sin depender del nombre de rol. Para esos contextos se necesita un login completo con contraseña.

El cambio revoca el registro Web previo, autentica al empleado entrante en el guard, destruye/regenera la sesión y CSRF, y crea un registro con su identidad, método pin y propietario del contexto de terminal. La identidad, el propietario de terminal y el alcance del empleado se revalidan bajo el lock SQL antes del cambio; una sesión PIN pierde acceso si se retiran sus permisos operativos. La autorización posterior usa únicamente al nuevo empleado: no mezcla permisos ni conserva los del administrador o empleado anterior. No se emiten ni transfieren tokens API. El contexto se comprueba en servidor antes de cada cambio; no basta manipular IDs o controles de la interfaz.

La atribución de futuras operaciones deberá tomar el actor del guard/contrato confiable del servidor, nunca de un user_id enviado por el cliente. Esta entrega registra el acceso y su identidad efectiva; la auditoría detallada de operaciones sigue pendiente y no hay operaciones POS implementadas.

La variable AUDIT_SECURITY_RETENTION_DAYS se lee en config/audit_security.php y se expone también como audit.security_retention_days en la configuración modular.

## Configuración y controles base

Variables opcionales, leídas en config/auth_access.php, sin modificar .env operativo:

| Variable | Valor predeterminado |
| --- | --- |
| AUTH_WEB_IDLE_MINUTES | 30 |
| AUTH_API_TOKEN_MINUTES | 480 |
| AUTH_MAX_WEB_SESSIONS | 5 |
| AUTH_MAX_API_SESSIONS | 5 |
| AUTH_FAILED_ATTEMPTS | 5 |
| AUTH_LOCK_SECONDS | 300 |
| AUDIT_SECURITY_RETENTION_DAYS | 365 |

Usar enteros positivos. Auth aplica el timeout Web al valor de session.lifetime en memoria; el SESSION_LIFETIME=120 del .env existente queda conservado como archivo y ya no determina el acceso autenticado. El driver file y su cifrado existentes se mantienen. Se fuerzan HttpOnly y SameSite=Lax; fuera de local/testing se exige cookie Secure y HTTPS para las rutas/servicios de credenciales. Un proxy TLS deberá configurarse como confiable de forma explícita, sin aceptar cabeceras de cualquier origen.

AttemptGuard cuenta fallos consecutivos por identidad y origen, con ventana configurable de 300 segundos y bloqueo de 300 segundos completos desde el quinto fallo. Login Web y API comparten contador; PIN y reautenticación tienen claves independientes. El éxito limpia el contador; la expiración permite nuevos intentos. El PIN exige terminal autorizada y el contador no depende de la cookie ni del estado de la interfaz. Se usan RateLimiter y locks nativos del caché existente. En varios nodos debe existir un almacén compartido con locks; limpiar el caché reinicia los contadores. El límite adicional de cambios de clave se conserva.

PHP debe omitir argumentos de excepciones: Auth habilita zend.exception_ignore_args en el proceso y falla si no puede protegerlos. Esto evita que contraseñas, PIN o tokens aparezcan en trazas de errores, sin modificar php.ini ni .env; mantiene mensaje técnico, archivo y líneas del error. El hosting puede habilitar la opción de antemano si restringe ini_set. Nunca introducir secretos deliberadamente en mensajes de excepciones ni logs.

## Verificación y pendientes

Las pruebas positivas usan SQL Server en fresapos_test con las salvaguardas existentes. La prueba de Livewire usa su protocolo HTTP real con cookies/middleware; no se dejó sin sesión el componente para adaptar el producto a un helper de pruebas. Se conservan los 111 escenarios históricos de 1.3b; la validación definitiva de 1.3c pasó con 135 pruebas y 1256 aserciones. Las expectativas de rutas/migraciones se amplían de forma explícita.

El [informe 1.3b](entrega-1.3b.md) contiene resultados, migración operativa e integridad. [OpenAPI](openapi.yaml) se actualizó manualmente; en 1.3c se comprobó sintaxis YAML y referencias internas con Ruby/Psych; sigue pendiente validar el esquema OpenAPI. No se instalaron dependencias para ese fin.

La implementación 1.3c y sus límites de verificación están descritos en [entrega-1.3c.md](entrega-1.3c.md). La aceptación local se verificó ejecutando la suite y los controles funcionales en Windows/SQL Server el 12 de noviembre de 2026. Azure SQL y cPanel requieren sus comprobaciones reales de TLS, red, drivers/ODBC y PHP web. No se conectó a Azure ni se desplegó en hosting.

Referencias primarias consultadas: [Sanctum para Laravel 13](https://laravel.com/docs/13.x/sanctum) y [manifest oficial](https://github.com/laravel/sanctum/blob/4.x/composer.json); se inspeccionó también el código instalado de Laravel, Sanctum y Livewire.

### Errores de formularios y Livewire

SensitiveErrors sanea los errores 5xx de login, account, admin y auth/* y de los componentes auth.login y auth.sessions. Aunque APP_DEBUG esté activo, la respuesta solo contiene un mensaje genérico, sin cuerpo de petición, credenciales, SQL ni trazas. El detalle técnico se conserva una sola vez en el log, con argumentos de excepciones deshabilitados. Los errores API conservan el contrato de Core; las demás vistas y componentes Livewire mantienen su comportamiento nativo. Las pruebas provocan un error SQL real únicamente en fresapos_test para comprobar ambas respuestas sensibles.

## Administración y auditoría — 1.3c

Los endpoints de sesiones y el contrato JSON se detallan en [entrega-1.3c.md](entrega-1.3c.md). Audit consume el contrato SecurityEvent de Core mediante un listener síncrono; no se agrega dependencia de Users hacia Auth ni Audit. La tabla audit_security_events conserva únicamente datos permitidos, con fechas UTC y referencias que no autentican. Retención mínima de seguridad: 365 días; no existe purga automática ni modificación de auditoría financiera.
