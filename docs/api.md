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

[openapi.yaml](openapi.yaml) documenta ping y las cuatro rutas de autenticación, esquemas, códigos, cabeceras y respuestas comunes. BearerAuth se aplica a las rutas protegidas; no a ping ni login. Se escribió manualmente sin paquetes adicionales. No hay parser YAML disponible en el proyecto: **validación sintáctica con parser y validación contra el esquema OpenAPI pendientes**. Las pruebas HTTP sí verifican el comportamiento real; no se presenta el YAML como validado automáticamente.

Ejecutar desde PowerShell: `.\scripts\local.cmd composer test`, `composer lint`, `composer analyse` y `composer validate --strict` con el mismo lanzador. La suite completa requiere acceso Windows a `fresapos_test`; no utiliza `fresapos` para las pruebas. [El informe](entrega-1.2c.md) contiene los comandos y resultados ejecutados.


