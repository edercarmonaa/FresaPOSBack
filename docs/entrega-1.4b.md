# FresaPOSBack — Entrega 1.4b: API e interfaz administrativa del catálogo

Fecha: 12 de noviembre de 2026, America/Mexico_City.

**ETAPA 1.4b: ACEPTADA localmente.** Suite completa, calidad, HTTP e integridad verificados. No se inicia 1.4c.

## A. Estado inicial

Repositorio limpio sobre **bf9ffb4**. Se leyeron la entrega 1.4a y los contratos de arquitectura, persistencia, API y seguridad relevantes; se inspeccionaron modelos, DTOs, consultas, repositorios, servicios, Policies, permisos, Audit, rutas, layout, Livewire, pruebas y migraciones. Catalog ya tenía toda la lógica de 1.4a, pero no adaptadores HTTP ni pantallas. No se reconstruyó el módulo.

Entorno conservado: Windows/PowerShell, PHP 8.3.36, Laravel 13.42.0, Livewire 4.4.8, Sanctum 4.4.0, Spatie Permission 8.5.0, laravel-modules 13.3.0 y SQL Server Express en `.\AUDITORIA`, con autenticación integrada de Windows. Aplicación local en `http://127.0.0.1:8080`.

Línea base ejecutada antes de modificar: **200 pruebas / 1516 aserciones**, todas satisfactorias, 127,703 s. Pint correcto; Larastan **nivel 5, 162 archivos, sin errores**; Composer validate --strict y check-platform-reqs correctos. `fresapos` tenía quince tablas, un administrador activo y catálogo vacío; `fresapos_test` no tenía tablas. Las cinco migraciones estaban aplicadas; las dos de 1.4a en lote 4.

## B. API REST

Se añadieron trece operaciones, todas con las convenciones Core existentes:

| Recurso | Operaciones y rutas relativas a `/api/v1/catalog` |
| --- | --- |
| Categorías | GET/POST `/categories`; GET/PUT/DELETE `/categories/{id}`; PATCH `/categories/{id}/status`. |
| Productos | GET/POST `/products`; GET/PUT/DELETE `/products/{id}`; PATCH `/products/{id}/status`; POST `/products/{id}/restore`. |

Los controladores solo obtienen el actor autorizado, delegan a CatalogAdministration y presentan DTOs mediante CategoryResource/ProductResource y ApiResponder. No escriben Eloquent ni ejecutan transacciones o auditoría por su cuenta. POST responde 201; el resto 200. DELETE responde `data: null` y siempre es lógico. No se inventó restauración de categorías.

Form Requests nativos: CategoryRequest, ProductRequest, CatalogListRequest, StatusRequest y base CatalogWriteRequest. Validan transporte, campos adicionales, permisos, ULID, tamaños y consultas. CatalogValidation delega normalización y decimales a **CatalogInput de 1.4a**; no es un segundo dominio. La comprobación previa de unicidad utiliza la misma collation SQL y devuelve 422; el servicio y el índice siguen siendo autoridad y una carrera concurrente conserva 409. No hay unicidad nueva para productos.

POST y PUT requieren nombre; producto requiere además categoría y precio. Opcionales omitidos en PUT se conservan. Se admiten enteros JSON o texto decimal exacto, sin float, exponentes, miles ambiguos o redondeo. Salida price como string con cuatro decimales; máximo `999999999999999.9999`.

Recursos con campos públicos snake_case, fechas UTC y categoría id/nombre. Imagen opcional como `{url, version}`; no se exponen disk/key, rutas físicas, auditoría ni modelos internos. El disco debe tener visibilidad pública y la URL ser HTTP(S) o relativa al origen. No se crean ni verifican archivos.

Respuestas Core: success/code/message/data/meta.request_id. Listados: data.categories o data.products y **meta.pagination** con current_page/per_page/total/last_page/from/to. Se conservan X-Request-Id, no-store y errores 400/401/403/404/409/422/429/500 seguros. No se introdujo otro manejador de errores API.

Se actualizó **docs/openapi.yaml**, sin especificación paralela: trece operaciones nuevas, bearer, permisos, filtros, parámetros, ULID, esquemas, respuestas y ejemplos. Comprobación local por lectura de rutas/referencias: **23 operaciones API, trece de Catalog y 283 referencias resueltas**, sin operaciones duplicadas ni endpoints ficticios. Esto **no es validación sintáctica YAML ni validación formal OpenAPI**; ambas permanecen pendientes al no existir una herramienta instalada para ejecutarlas. No se instalaron dependencias.

## C. Interfaz Blade/Livewire

Dos páginas en el layout existente: `/admin/catalog/categories` y `/admin/catalog/products`. Navegación **Catálogo** integrada una sola vez en resources/views/layouts/app.blade.php, con enlaces condicionados por capacidades; se conserva cuenta/login/administración anterior. No hay segundo panel ni framework adicional.

Categories permite crear, editar nombre/descripción/prioridad/estado, activar/desactivar y eliminar lógicamente. Conserva el orden aprobado en 1.4a por prioridad, nombre e id ascendentes; editar prioridad es el mecanismo para ordenar. No se cambió CategoryQuery para aceptar ordenamientos ajenos al contrato confirmado.

Products permite crear/editar, cambiar precio, activar/desactivar, enviar a la papelera y restaurar. La papelera muestra solo eliminados y exige restore. Restaurar vuelve activo únicamente si el servicio acepta la categoría; un conflicto mantiene el registro eliminado y muestra un error sin operación parcial. Se conserva la categoría actual inactiva al editar nombre/precio, sin ofrecerla como nueva asignación. Los selectores consultan hasta cien coincidencias de categorías, con buscador para acotarlas; las opciones nuevas se consultan como activas en SQL.

Formularios con validación del servidor, resumen role=alert, conservación de valores, mensajes role=status, procesamiento y botones deshabilitados mientras se ejecuta la solicitud. Confirmaciones Livewire para estados, eliminación y restauración. Los identificadores de edición están Locked. No se confía en ocultar botones para autorizar.

Vistas con labels, captions de tabla, navegación identificada, flex/grid adaptables y contenedor de desplazamiento horizontal para tablas en tablet. Se reutilizan colores/tipografía y Tailwind del layout. No se rediseñó la aplicación. Sin archivos de imagen se muestra un recuadro con el **nombre del producto**; con referencia existente se muestra su URL pública. No hay input file ni subida/conversión/borrado físico. El informe 1.4a es histórico: cualquier referencia allí a procesar imágenes en 1.4b queda sustituida por la instrucción de reservarlo para **1.4c**.

La suite verifica el HTML renderizado y las acciones del servidor y también solicitudes HTTP reales del protocolo Livewire. La ejecución de JavaScript y la revisión visual en dispositivos físicos no se presentan como comprobadas; siguen pendientes como validación de experiencia de usuario.

## D. Filtros y paginación

Se usan CategoryQuery/ProductQuery y sus repositorios originales: normalización, prefijos de palabra, collation CI_AI_SC y consultas parametrizadas. Sin carga completa ni paginación en memoria.

- Comunes: search, estado, page 1..1000000, per_page **10/25/50/100** (25 predeterminado) y deleted=without|with|only para consulta administrativa autorizada.
- Productos: category_id, sort=name|price y direction=asc|desc. Desempate estable del repositorio.
- Categorías: sort=display_order y direction=asc; se rechaza cualquier variante incompatible con 1.4a.
- Web conserva filtros al cambiar página y reinicia página al cambiar búsqueda/estado/categoría/tamaño/orden/papelera.

Se valida search con el dominio (100 unidades UTF-16 y hasta ocho palabras); se rechazan tamaños, columnas, direcciones, identificadores y parámetros desconocidos. La vista administrativa usa estado propio del producto, como 1.4a; `is_active=true` no garantiza categoría vendible. El futuro flujo de ventas debe utilizar CatalogReader::forSale, sin modificar esta semántica.

CatalogPresentation reutiliza los DTOs para categoría/nombre y cachea los nombres de categoría durante una respuesta/render. Hay una consulta adicional por categoría distinta de la página, acotada por cien productos. La mejora futura de consultas agrupadas no justifica cambiar ahora los contratos de 1.4a.

## E. Permisos y protección

Los seis permisos reales se conservaron sin altas ni reasignaciones:

| Capacidad | Permiso |
| --- | --- |
| Leer productos; leer categorías alternativamente a manage | catalog.products.view |
| Crear productos | catalog.products.create |
| Editar y cambiar precio | catalog.products.update |
| Cambiar estado y eliminar productos | catalog.products.deactivate |
| Papelera/restauración de productos | catalog.products.restore |
| Administrar categorías y consultar sus eliminadas | catalog.categories.manage |

La API de escritura de productos exige además view, pues devuelve la representación del recurso; modificar is_active mediante PUT exige también deactivate. Antes de mutar se verifica la lectura para impedir una escritura seguida por 403 de serialización. Los servicios mantienen su autorización transaccional de 1.4a, con identidad actual y lock.

Para entrar al administrador de productos hacen falta view y al menos una capacidad de escritura de producto. Categorías exige manage. No se usa el nombre de rol como bypass ni se exige users.view al gestor de catálogo. Administrador conserva sus capacidades; supervisor/cashier/waiter actuales pueden leer por API, pero no entrar al catálogo administrativo ni escribir/restaurar. Se probó también un editor limitado para impedir manipulación de estado.

Se reutilizan auth.no-store, auth:sanctum, auth.access.api y auth.access.web. CatalogAdminAccess se registra como middleware persistente Livewire; las acciones y render también consultan CatalogAccess/IdentityAccess/CatalogPolicy. La sesión Web revocada se rechaza en la siguiente actualización real; retirar permisos tiene efecto sin nuevo login. CSRF se verifica activando el middleware real en pruebas.

Único cambio de seguridad fuera de Catalog: **Modules/Auth/app/Http/SensitiveErrors.php** amplía el ámbito del sanitizador existente a las dos páginas y componentes del catálogo, para no mostrar trazas/SQL/rutas internas en HTML o Livewire. No cambia autenticación, sesiones ni credenciales. Se probó API y HTML con APP_DEBUG activo e inactivo. Los diagnósticos siguen en logs privados bajo las convenciones previas.

## F. Auditoría

La responsabilidad permanece en ManageCatalog de 1.4a y Audit: categorías creadas/editadas/activadas/desactivadas/eliminadas; productos creados/editados, cambio de precio, estados, eliminación y restauración. Los controladores y Livewire **no emiten SecurityEvent**.

Editar un producto con precio distinto produce product.updated y product.price_changed como dos hechos distintos ya definidos en 1.4a; no dos copias del mismo evento. Las pruebas comprueban una sola creación, cambio de precio, eliminación y restauración por operación, más request_id para la API. Conflictos no dejan auditoría ni datos parciales; se conservan las pruebas anteriores de rollback por fallo de auditoría.

## G. SQL Server

Sin migraciones nuevas, cambios de esquema ni ejecución de migrate en la base operativa. Se mantienen las quince tablas, ULID nchar(26), decimal(19,4), FK con NO ACTION, CHECK e índices de 1.4a. No hay tablas de ejemplo ni productos/categorías de prueba en fresapos.

Integración exclusivamente en **fresapos_test**, utilizando CatalogSqlServerTestCase, AuthSqlServerTestCase y TestDatabaseGuard existentes, que verifican nombre configurado y DB_NAME() antes del DDL. Las migraciones de prueba se crean y desmontan únicamente allí. No se usó SQLite, Docker, migrate:fresh, db:wipe ni variantes destructivas sobre fresapos.

## H. Pruebas

Comandos desde PowerShell y raíz del repositorio:

```powershell
.\scripts\local.cmd composer test
.\scripts\local.cmd composer lint
.\scripts\local.cmd composer analyse
.\scripts\local.cmd composer validate --strict
.\scripts\local.cmd composer check-platform-reqs
.\scripts\local.cmd php vendor/bin/phpunit --filter CatalogHttpTest
.\scripts\local.cmd php artisan route:list --except-vendor
```

| Comprobación | Resultado real de cierre |
| --- | --- |
| composer test | **225 pruebas / 1974 aserciones**, 226,293 s, 88 MB; sin fallos, errores, avisos ni omisiones. Conserva las 200 anteriores. |
| composer lint | Passed. |
| composer analyse | **Nivel 5, 186 archivos, sin errores**; frente a 162 archivos de línea base. |
| composer validate --strict | composer.json válido. |
| composer check-platform-reqs | Todos los requisitos satisfechos, incluidos PHP 8.3.36 y sqlsrv/pdo_sqlsrv 5.13.3. |
| route:list --except-vendor | **38 rutas propias**: 23 previas, trece operaciones API y dos páginas Web nuevas. La API total tiene 23 operaciones. |
| Correspondencia OpenAPI/rutas y referencias | 23 operaciones documentadas coinciden con las reales; 283 referencias internas resueltas, sin duplicados. No equivale a validar YAML/OpenAPI formalmente. |
| Datos operativos y nueve archivos protegidos | Coincidencia íntegra antes/después; catálogo operativo vacío y un administrador activo. |
| Base de pruebas al finalizar | fresapos_test con **cero tablas**, sin residuos. |

Se añadieron **25 pruebas de integración HTTP/Livewire**: CRUD categorías/productos, estados, duplicados, filtros/orden/paginación real SQL, validación/whitelist, máximos decimales y rechazo de floats, ULID/404, referencias públicas de imágenes, papelera/restauración/conflictos, permisos por rol/usuario limitado, sesiones revocadas, retirada de permisos, contraseña pendiente/inactividad/borrado, CSRF, identificador Locked, conservación de formulario, navegación/confirmaciones y errores sin secretos en ambos modos de debug.

Las pruebas previas mantienen sus métodos/datasets. ApiConventionsTest agrega los trece nombres nuevos a su lista exacta conservando los diez existentes; ModuleRegistrationTest añade los dos controladores a su lista autorizada. No se retiraron pruebas, migraciones esperadas ni comprobaciones de seguridad o arquitectura.

Durante desarrollo se corrigió una declaración abstracta de rules necesaria para Larastan, sin ignores ni baseline. Las primeras ejecuciones nuevas revelaron expectativas de HTML antes de abrir el formulario, invocación del helper tras un montaje ya prohibido, fixtures con nombres incompatibles con la restricción SQL existente, estado de assets Livewire entre solicitudes del mismo proceso de tests y una expectativa demasiado estricta del encabezado de caché. Se corrigieron los fixtures/helpers/expectativas manteniendo la intención de cada prueba. El stub de excepción sustituye un mock sin expectativas para evitar un aviso de PHPUnit. No se ocultaron fallos ni se modificó el dominio para acomodar los tests.

Comprobación HTTP real en el servidor existente 8080: `/`, `/up`, `/api/v1/ping`, `/login` y JavaScript de Livewire **200**; login conserva CSRF y snapshot; ambas páginas privadas **302** sin sesión; productos API **401** sin bearer. Las operaciones autenticadas se validan exclusivamente con fixtures SQL de prueba; no se usaron credenciales del administrador operativo.

## I. Integridad y archivos

La comparación privada inicial/final de **todas las filas completas de las quince tablas**, ordenadas y resumidas mediante SHA-256, coincidió sin diferencias. Incluye migrations, usuarios/contraseña/historial, roles, permisos, asignaciones, sesiones y auditoría. Permanecen un usuario y un administrador activo; categorías y productos operativos continúan en cero. Las cinco filas de migraciones están intactas y las dos de 1.4a siguen en lote 4. No hubo DDL ni escrituras de catálogo sobre fresapos.

Se verificaron byte a byte nueve archivos protegidos sin diferencias: **.env, .env.testing, config/database.php, config/session.php, composer.json, composer.lock, phpstan.neon, phpunit.xml y Modules/Users/config/security.php**. Se conservan credenciales, Windows Authentication, configuración de sesión y niveles de calidad. La base fresapos_test quedó vacía tras la suite. Para contar sus tablas al cerrar se utilizó una conexión efímera de solo lectura a la misma instancia, sin editar configuración ni archivos de entorno.

Archivos funcionales añadidos: Modules/Catalog/app/Http (controladores, requests, resources, middleware, componentes y cuatro helpers), routes/api/v1.php y seis vistas Blade (dos páginas, dos componentes, navegación y paginación). Se modificaron el proveedor y rutas Web de Catalog, el layout compartido y el ámbito del sanitizador descrito en E. Se añadieron pruebas y se ampliaron dos expectativas existentes; documentación: README, CHANGELOG, API, OpenAPI y este informe.

Se preservan modelos, repositorios, contratos, consultas, servicios, Policies y migraciones de 1.4a. No hay commits ni pushes. Las evidencias locales catalog-1.4b-* están en .tools, ignorado por Git; no se publican huellas privadas de usuarios ni credenciales.

## J. Pendientes y límite de la entrega

- **1.4c**: carga autorizada de imágenes, límite/formato, conversión WebP, validación, archivos públicos, reemplazo/borrado físico seguro y coherencia archivo/SQL. No se inició.
- Validación formal YAML/OpenAPI con herramienta autorizada; no se instaló una para esta entrega. La comprobación de rutas/referencias no la sustituye.
- Prueba visual de ejecución JavaScript y dispositivos físicos/tablet; cobertura actual de render/acciones/protocolo HTTP y estructura adaptable, sin atribuirle pruebas de navegador no ejecutadas.
- Azure SQL y hosting cPanel siguen pendientes de entorno autorizado, ODBC/pdo_sqlsrv compatibles, conectividad/firewall/TLS y capacidades PHP/ionCube. No hubo conexión a Azure.
- Resolver la limitación histórica de Tailwind CDN antes de producción, conforme a docs/frontend.md; no se instaló Node/Vite ni se cambió el frontend de base.

La administración del catálogo queda preparada para registrar datos reales con los permisos existentes. La entrega se detiene en **1.4b**; no implementa inventario, ventas ni procesamiento de imágenes.
