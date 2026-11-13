# API REST v1

La etapa 1.3b incorpora autenticación Sanctum, conservando el transporte de 1.2c y sin funcionalidades POS. El endpoint técnico público es `GET /api/v1/ping`; no consulta la base, responde `data: {"status":"ok"}` y no sustituye un futuro diagnóstico autenticado. `/up`, la página principal y Livewire conservan sus rutas.

## Rutas y versiones

`bootstrap/app.php` añade **una vez** el prefijo `api` y el grupo nativo `api`. `routes/api.php` agrega `v1` y carga `Modules/<Module>/routes/api/v1.php` de los módulos habilitados cuando existe el archivo. Los archivos modulares declaran rutas relativas, sin repetir `api` ni `v1`. Nombres: `api.v1.<module>.<action>`, por ejemplo `api.v1.core.ping`. Para v2 se añadirá otro grupo y archivos `routes/api/v2.php`, conservando v1. No se crean carpetas vacías en los demás módulos.

Las rutas API no deben redirigir: devuelven JSON mediante el contrato de Core. Las excepciones de métodos sin cuerpo (HEAD, 204, 304) conservan las reglas HTTP; las rutas actuales ofrecen ping y autenticación con cuerpo JSON. El normalizador no convierte redirecciones ni streams en un protocolo nuevo; futuros endpoints de descarga necesitan una decisión expresa. Los adaptadores deben evitar contenido sensible incluso en respuestas de éxito.

## Contratos públicos y respuestas

Los módulos pueden inyectar `Modules\Core\Contracts\ApiResponder`. Su implementación privada `Http/ApiResponseFactory` añade el sobre; el middleware también normaliza respuestas JSON nativas de API para evitar formatos accidentales. Una respuesta ya construida usa un tipo interno identificable y no se envuelve dos veces. `success($request, $data, $meta, $status)` admite metadatos adicionales y un estado 2xx con cuerpo. `error(...)` permite detalles estructurados deliberados, nunca mensajes de una excepción interna.

Éxito: `success` booleano verdadero, `code`, `message`, `data`, `meta`. Error: `success` falso, `code`, `message`, `meta`; `errors` aparece solo si hay detalles estructurados, sin `data`. `meta.request_id` siempre se genera desde el contexto y no puede sobrescribirse mediante metadatos del consumidor. Los ejemplos de ejecuciones reales están en [el informe](entrega-1.2c.md).

Los mensajes usan el idioma configurado de Laravel, sin interpretar automáticamente Accept-Language. `.env` conserva `APP_LOCALE=es` y fallback `en`. Core registra sus diccionarios `lang/es` y `lang/en` mediante el proveedor modular existente. Las pruebas verifican ambos idiomas. No se cambia el idioma global de web/Livewire.

El enum respaldado por string `Core\Contracts\ApiCode` es el catálogo único, tipado y sin dependencias nuevas. El identificador estable es distinto del estado HTTP; los clientes deciden por `code`, nunca por el mensaje traducido. Su método `status()` proporciona el estado habitual; el adaptador conserva estados HTTP específicos cuando corresponde.

| Código | HTTP habitual |
| --- | --- |
| OPERATION_SUCCESS | 200 |
| BAD_REQUEST | 400 |
| UNAUTHENTICATED | 401 |
| FORBIDDEN | 403 |
| NOT_FOUND | 404 |
| METHOD_NOT_ALLOWED | 405 |
| CONFLICT | 409 |
| VALIDATION_ERROR | 422 |
| TOO_MANY_REQUESTS | 429 |
| INTERNAL_ERROR | 500 |
| SERVICE_UNAVAILABLE | 503 |

## Correlación de solicitudes y logs

El middleware global `AssignApiRequestId`, condicionado a `/api` y `/api/*`, se ejecuta antes de los middleware de ruta. Así cubre también los 404 que nunca encuentran una ruta y las respuestas de mantenimiento. Se acepta `X-Request-Id` solo si coincide completamente con `[A-Za-z0-9_-]{1,64}`; CR/LF, espacios y caracteres ajenos provocan su descarte. El valor rechazado no se registra ni devuelve. Si falta o no es válido se genera un ULID nativo, representado en minúsculas, sin una abstracción adicional.

El identificador validado se devuelve en `X-Request-Id` y `meta.request_id`. Se comparte en el contexto de los canales de log durante la petición y se retira en `finally`, restaurando el contexto previo para evitar contaminación entre solicitudes. Cada petición sin ID entrante genera uno distinto. Un cliente puede repetir un ID válido: no se promete unicidad global de IDs proporcionados por clientes. No es secreto, credencial ni clave de idempotencia financiera.

No se modifica CORS. Exponer `X-Request-Id` a un futuro cliente web de otro origen queda **pendiente** de acordar orígenes, credenciales y cabeceras; el cliente nativo puede leerlo directamente.

## Errores seguros

`bootstrap/app.php` mantiene la decisión JSON nativa y conecta el reporte y la finalización de respuestas con `Core/Http/ApiExceptionHandler`. La normalización se aplica a `/api/*` (también sin Accept) y a errores de otras rutas que esperan JSON, excluyendo solicitudes Livewire. Las nuevas pantallas de autenticación y el componente auth.login sanean además los errores 5xx HTML/Livewire para evitar reflejar credenciales, incluso con debug activo. Las demás vistas y componentes mantienen su manejo nativo. El middleware y la cabecera de correlación solo se aplican al espacio API; los errores JSON externos a él incluyen un ID en su sobre y log, sin añadir la cabecera global ni normalizar sus éxitos.

Se cubren autenticación, autorización, validación, rutas inexistentes, método no permitido, conflictos, límites, indisponibilidad y excepciones imprevistas. Se preservan `Allow`, `Retry-After`, `WWW-Authenticate` y cabeceras de límite. Se usa el finalizador de Laravel para sanear incluso excepciones que producen su propia respuesta. El código no devuelve `getMessage()`, SQL, bindings, rutas, configuración ni trazas al cliente.

Un error 500 siempre devuelve un mensaje genérico, tanto con debug activo como inactivo. El detalle técnico de la excepción permanece en el log con el mismo ID. `QueryException` siempre se trata como error interno y nunca como un error de dominio público. El reporte explícito mediante `report($exception)` también funciona aunque después se responda 200. Un WeakMap evita duplicar el reporte del mismo objeto al renderizar. Las respuestas 5xx directas, sin excepción, registran estado e ID. Los logs pueden contener información técnica sensible: su acceso y retención deben restringirse en el despliegue.

El limitador mínimo `api-v1` permite 60 solicitudes por minuto por IP, usa el almacén de caché ya configurado y no añade Redis. Su prueba reduce el límite solo en memoria para provocar un 429 real. La política definitiva por identidad, rutas y proxies confiables queda para autenticación/despliegue; no es una garantía distribuida con caché local.

## Validación HTTP y dominio

Los futuros Form Requests de cada módulo declaran autorización, reglas, mensajes y atributos de transporte; las reglas de negocio pertenecen a Domain/Application. Una `ValidationException` nativa se transforma en 422 y conserva los nombres con puntos, como `items.0.name`, cada uno con una lista de mensajes. No introducir SQL ni datos internos en mensajes públicos ni personalizar `failedValidation` con una respuesta ajena al contrato.

`Core/Http/Requests/ApiFormRequest` proporciona la convención utilizada por la fixture técnica de Core: clona el traductor del validador y combina sus mensajes con el diccionario modular, sin cambiar el traductor de web/Livewire. Los mensajes comunes se incluyen en español; nuevas reglas deben incorporar su traducción, pues el fallback nativo puede estar en inglés. La clase Http es interna: los módulos funcionales usarán sus propios Form Requests y diccionarios, respetando que no pueden importar `Core\Http`. Comparten `ApiResponder`, `ApiCode` y el manejador global mediante los contratos públicos, sin ampliar la matriz de dependencias.

Se compararon dos opciones para excepciones de dominio: una interfaz con código/estado fuerza al dominio a conocer el transporte; un registro permite conservar excepciones ordinarias. Se aprobó el **registro mínimo** `Core\Contracts\ApiExceptionMappings`. Un proveedor modular registra su clase concreta de excepción y un `ApiCode` de error; Core resuelve la clase más específica y luego sus padres, sin importar módulos funcionales. El mensaje público proviene del catálogo, nunca de la excepción. Las excepciones desconocidas son 500. Las excepciones nativas de Laravel conservan su semántica antes de consultar el registro. No se creó una jerarquía de negocio ni un framework de excepciones.

## Autenticación y permisos — 1.3b

Sanctum 4.4.0 autentica exclusivamente por Bearer en API; una cookie Web no sustituye el token. POST /api/v1/auth/login emite el token una vez; GET /api/v1/auth/me devuelve identidad, roles y permisos autorizados; POST /api/v1/auth/logout revoca el token actual; POST /api/v1/auth/password completa el cambio obligatorio y rota los accesos. Ping sigue público. Véase [autenticacion.md](autenticacion.md) para cuerpos, vigencia y restricciones.

Los permisos se consultan en servidor con Spatie/Gate, conservando cuatro roles y 44 capacidades. Sin identidad: 401; sin permiso: 403. Credenciales incorrectas, inactivas o eliminadas producen el mismo INVALID_CREDENTIALS, sin enumeración. Se añaden SESSION_EXPIRED, PIN_INVALID y PASSWORD_CHANGE_REQUIRED al catálogo de Core. No hay endpoint de login mediante PIN ni aprobación de dispositivos.

## OpenAPI y verificación

[openapi.yaml](openapi.yaml) documenta ping, autenticación y administración de sesiones, esquemas, códigos, cabeceras y respuestas comunes. BearerAuth se aplica a las rutas protegidas; no a ping ni login. Se escribió manualmente sin paquetes adicionales. En 1.3c se verificaron sintaxis YAML y 102 referencias internas mediante Ruby/Psych, sin instalar dependencias; **validación formal contra el esquema OpenAPI pendiente**. Las pruebas HTTP sí verifican el comportamiento real; no se presenta el YAML como validado automáticamente.

Ejecutar desde PowerShell: `.\scripts\local.cmd composer test`, `composer lint`, `composer analyse` y `composer validate --strict` con el mismo lanzador. La suite completa requiere acceso Windows a `fresapos_test`; no utiliza `fresapos` para las pruebas. [El informe](entrega-1.2c.md) contiene los comandos y resultados ejecutados.



## Sesiones — implementación 1.3c

Todas estas rutas requieren Sanctum, auth.access.api y contraseña obligatoria completada:

| Método | Ruta bajo /api/v1/auth | Operación |
| --- | --- | --- |
| GET | /sessions | Lista propia. |
| DELETE | /sessions/{id} | Revoca un registro; ajeno requiere users.status. |
| DELETE | /sessions | Revoca todos los accesos propios; JSON `{"others":true}` conserva el actual. |
| GET | /users/{userId}/sessions | Consulta autorizada con users.status. |
| DELETE | /users/{userId}/sessions | Revoca accesos Web/API del usuario con users.status. |

Las respuestas usan el mismo sobre y X-Request-Id. Listados devuelven data.sessions; revocaciones devuelven data null. Los IDs son ULID de registros administrativos, nunca cookies ni IDs de token. La lista contiene hasta 50 registros, priorizando no revocados y vigencia descendente; incluye channel, client_name cuando está disponible, user_id, created_at, last_activity_at, expires_at, revoked_at y state (active/expired/revoked). No devuelve IP, hashes ni secretos. Identificadores mal formados en la ruta producen 404, cuerpo inválido 422, actor sin autorización 403, contexto inválido 401 y exceso 429 con Retry-After cuando corresponde al bloqueo de autenticación. Revocar de nuevo un registro ya revocado conserva 200 mientras el actor mantenga otro contexto válido.

Implementación y validación local definitiva: [entrega-1.3c.md](entrega-1.3c.md). OpenAPI incluye estas rutas; su sintaxis YAML se verificó con Ruby/Psych durante la implementación anterior. Esa comprobación no se repitió en Windows ni sustituye la validación formal del esquema OpenAPI, aún pendiente. Las rutas sí se probaron mediante HTTP/SQL en esta aceptación.
# Catálogo administrativo — 1.4b

Rutas protegidas por bearer Sanctum y acceso vigente bajo `/api/v1/catalog`:

| Recurso | Operaciones |
| --- | --- |
| `categories` | GET/POST colección; GET/PUT/DELETE `{id}`; PATCH `{id}/status`. |
| `products` | GET/POST colección; GET/PUT/DELETE `{id}`; PATCH `{id}/status`; POST `{id}/restore`. |

Los identificadores son ULID. DELETE de producto/categoría es exclusivamente lógico y responde con `data: null`. No hay restauración de categorías ni borrado definitivo de productos. Se preservan las reglas de los servicios de 1.4a. Las imágenes usan las operaciones dedicadas de 1.4c descritas a continuación.

## Imágenes de producto — 1.4c

`POST /api/v1/catalog/products/{id}/image` acepta multipart/form-data con un único campo `image`: JPEG, PNG o WebP real, máximo **5242880 bytes (5 MiB)**. No admite SVG/GIF/animaciones, archivos corruptos, dimensiones peligrosas, rutas, disco o actor proporcionados por el cliente. Se comprueba contenido, límites y decodificación; el nombre/MIME del navegador no determinan el resultado. Devuelve 200 con el ProductResource existente, incluyendo image.url y image.version nuevas. No se necesita actualizar ni cerrar la sesión del cliente para recibir la nueva versión al volver a consultar el producto.

`DELETE /api/v1/catalog/products/{id}/image` no requiere cuerpo. Devuelve 200 con el producto conservado e image=null; es idempotente si no hay imagen. Ambas operaciones requieren bearer vigente, `catalog.products.view` y `catalog.products.update`, identidad activa y contraseña cambiada. El producto debe existir y no estar eliminado. El catálogo no tiene propietario/tenant por producto: los permisos son globales al catálogo; no se inventa una regla de propiedad que no existe. El cliente nunca elige archivos o rutas de otro producto.

Se reutilizan success/code/message/data/meta.request_id y X-Request-Id. Errores de archivo/formato/límite: 422; sesión: 401; permiso: 403; ULID inválido/recurso inexistente o eliminado: 404; fallo interno de proceso/almacenamiento/persistencia: 500 genérico sin rutas ni trazas. El servidor/proxy puede rechazar cuerpos superiores a sus propios límites antes de la validación del archivo; revisar esos límites al desplegar. La API no permite image/image_disk/image_key dentro del JSON ordinario de productos.

Los archivos finales públicos son `catalog/products/ULID.webp` en el disco configurado, con URL obtenida mediante Filesystem y versión igual al ULID del nombre. Cada sustitución crea otro archivo; nunca sobrescribe la URL anterior. La referencia SQL cambia con auditoría transaccional; el archivo previo se retira después del commit únicamente si ningún producto, incluidos eliminados, lo referencia. Fallos de limpieza conservan un journal privado y no revierten un commit confirmado. Detalles, límites, comandos de recuperación y despliegue en [entrega-1.4c.md](entrega-1.4c.md).

Lectura de productos exige `catalog.products.view`; categorías permite este permiso o `catalog.categories.manage`. Escribir categorías exige manage. Escribir productos exige view más create/update/deactivate/restore según la acción; enviar `is_active` en PUT también exige deactivate. `deleted=with|only` exige restore para productos y manage para categorías. La identidad debe estar activa y haber cambiado su contraseña inicial. Las cookies de la Web no autentican la API.

POST/PUT categoría requiere `name`, admite description/is_active/display_order. POST/PUT producto requiere name/category_id/price, admite description/is_active. En PUT los opcionales omitidos se conservan. La validación reutiliza CatalogInput y SQL CI_AI_SC: espacios normalizados y nombres de categorías únicos, incluidos los eliminados. No hay unicidad inventada para productos. Precio recomendado como **texto decimal**, por ejemplo `"25.5000"`; se aceptan enteros JSON exactos, se rechazan números JSON fraccionarios, exponentes, negativos, separadores ambiguos y más de cuatro decimales. Máximo `999999999999999.9999`. La salida siempre conserva cuatro decimales. Campos adicionales se rechazan con 422.

Filtros: `search` (100 unidades UTF-16, máximo ocho palabras), `is_active` (true/false/1/0), `deleted=without|with|only`, page 1..1000000 y per_page **10/25/50/100** (25 predeterminado). Productos: category_id, sort=name|price y direction=asc|desc. Categorías conserva el orden de 1.4a: prioridad, nombre e id ascendentes; solo acepta sort=display_order y direction=asc. Filtros y columnas desconocidas reciben 422. `is_active` representa el estado propio del registro; la futura venta debe usar CatalogReader::forSale para exigir también categoría disponible.

Los Resources exponen DTOs públicos, categoría id/nombre y, cuando existe, imagen como `{url, version}`, sin disk/key ni rutas de servidor. No verifican ni crean archivos. Si una referencia existente usa un almacenamiento público mal configurado, se devuelve un error genérico, nunca una ruta interna.

Se reutiliza el sobre Core con `success`, `code`, `message`, `data`, `meta.request_id`; los listados usan `data.categories` o `data.products` y `meta.pagination`. Ejemplo de colección vacía:

```json
{"success":true,"code":"OPERATION_SUCCESS","message":"Operación completada correctamente.","data":{"products":[]},"meta":{"request_id":"catalog-example","pagination":{"current_page":1,"per_page":25,"total":0,"last_page":1,"from":null,"to":null}}}
```

Creación: 201; restantes operaciones: 200. Errores públicos: 400/401/403/404/409/422/429/500. La consulta de un borrado sin modo administrativo es 404; categoría inactiva/restauración inconsistente es 409; duplicado detectado en formulario es 422 y una carrera de unicidad sigue siendo 409 transaccional. Se mantienen X-Request-Id y no-store, también cuando el manejador de excepciones genera el error fuera del middleware de autenticación. No se devuelven detalles internos, incluso con APP_DEBUG activo. La [especificación existente](openapi.yaml) incluye las operaciones y esquemas y pasa la validación formal OpenAPI 3.1 con openapi-spec-validator 0.9.0 mediante `composer openapi`. La entrega 1.4d contrasta además 34 respuestas reales de las 15 operaciones de Catalog con sus esquemas, incluidos decimales y errores. Es tooling aislado de desarrollo, sin dependencias PHP nuevas.
