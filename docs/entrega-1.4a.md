# FresaPOSBack — Entrega 1.4a: fundamentos del catálogo

Fecha: 12 de noviembre de 2026, America/Mexico_City.

**Estado: etapa 1.4a completada y validada localmente.** Esta entrega se limita al dominio, persistencia y servicios de Catalog. No inicia 1.4b.

## A. Estado inicial

Se inspeccionaron Git, informes previos (especialmente 1.3a–1.3c), arquitectura/persistencia, Catalog, Core, Users, Audit, contratos, DTOs, Policies, permisos, migraciones, rutas y salvaguardas SQL. Catalog tenía solo el esqueleto registrado, sin modelos, servicios, migraciones de negocio ni endpoints. Git estaba limpio sobre **0a0bc26**.

Entorno real: Windows, PHP 8.3.36, Laravel 13.42.0, Livewire 4.4.8, Sanctum 4.4.0, Spatie Permission 8.5.0, laravel-modules 13.3.0, SQL Server Express 17.0.1155.3 (.\AUDITORIA), Windows Authentication y drivers sqlsrv/pdo_sqlsrv 5.13.3. Se reutilizan normalización Unicode (intl/mbstring instalados), Eloquent/ULID nativos, TransactionManager, IdentityAccess/UserSnapshot, permisos efectivos de Spatie, SecurityEvent y el listener de Audit. No se instalaron dependencias ni se cambió Composer.

La línea base se ejecutó antes de implementar: **135 pruebas, 1256 aserciones**, en 130,904 s; Pint passed; Larastan nivel 5, 138 archivos sin errores; Composer validate --strict y check-platform-reqs correctos. Ambas bases efectivas fueron verificadas: fresapos con trece tablas y un administrador activo; fresapos_test vacía y protegida.

## B. Modelo de datos

### catalog_categories

- id ULID nchar(26), PK clustered nativa.
- name nvarchar(150), obligatorio, normalizado y único con Latin1_General_100_CI_AI_SC.
- description nvarchar(1000), opcional.
- is_active bit; display_order int no negativo.
- created_at, updated_at y deleted_at; fechas de aplicación en UTC y SoftDeletes.
- Índice de deleted_at/is_active/display_order; unicidad global del nombre, incluidos eliminados.

### catalog_products

- id y category_id ULID nchar(26); FK real a catalog_categories con **NO ACTION**, sin cascada.
- name nvarchar(150), con collation CI_AI_SC e índice; description nvarchar(2000) opcional.
- price **decimal(19,4)** no negativo, representado por cadenas exactas.
- is_active bit; image_disk nvarchar(32) e image_key nvarchar(128), ambos opcionales y coherentes entre sí.
- created_at, updated_at, deleted_at; SoftDeletes.
- Índices por category_id/deleted_at/is_active y deleted_at/is_active/price.
- CHECK de nombre sin espacios externos/repetidos ni tabuladores/saltos de línea; CHECK de precio y referencia de imagen.

Los modelos Eloquent Category y Product viven en Infrastructure/Models. Category tiene muchos productos; Product pertenece a una categoría y puede recuperar su categoría eliminada mediante withTrashed para conservar referencias. forceDelete de instancias se rechaza. La FK protege a una categoría referenciada también ante DELETE SQL directo. No se promete impedir mediante ORM todo SQL administrativo que eluda los servicios.

No hay inventario, costos, SKU, variantes, modificadores ni tablas de ventas.

## C. Reglas de negocio

### Nombres, estados y categorías

CatalogText normaliza Unicode a NFC, recorta espacios externos y comprime espacios Unicode repetidos conservando puntuación y acentos. Los nombres requieren letras o números y no admiten caracteres de control/formato. Los límites se verifican en unidades UTF-16 para evitar que SQL Server trunque caracteres suplementarios. Las descripciones son opcionales y se validan como texto normalizado, sin controles.

La unicidad de categorías se confirma en SQL y en pruebas con mayúsculas, acentos y espacios equivalentes. Los nombres de categorías eliminadas quedan reservados; no se liberan silenciosamente. Los productos no requieren nombres únicos.

No se asignan categorías inexistentes, inactivas o eliminadas a productos nuevos ni a nuevas asignaciones. Desactivar o eliminar lógicamente una categoría **no modifica nombre, precio, estado ni fechas de sus productos**. Sí impide ofrecerlos en el contrato operativo forSale. Un producto existente puede editar su nombre/precio conservando una categoría inactiva, pues no constituye una nueva asignación; activarlo exige categoría válida.

### Precios

Price acepta enteros exactos o cadenas decimales no negativas, hasta quince dígitos enteros y cuatro fraccionarios, y devuelve una cadena canónica con cuatro decimales. Rechaza float, booleanos, notación científica, comas, negativos, exceso de escala y desbordamiento; no redondea ni calcula mediante punto flotante. changePrice recibe entrada sin coerción a string para poder rechazar floats incluso en llamadas PHP directas.

La representación coincide con decimal(19,4) y el cast Eloquent decimal:4. Se probó el máximo 999999999999999.9999 y una magnitud/fracción que no debe pasar por float. Solo los servicios autorizados exponen cambios de precio; no existe una edición de precio desde una operación de venta.

### Eliminación y restauración

Eliminar productos usa SoftDeletes, conserva referencias y emite auditoría. El contrato operativo excluye productos inactivos o eliminados y categorías no disponibles. Los listados administrativos permiten ver estados y eliminados mediante filtros explícitos.

Restaurar exige que el producto esté eliminado y que su categoría exista, esté activa y no eliminada. **El producto vuelve activo**, incluso si se eliminó estando inactivo. Si su categoría no es compatible, se devuelve CatalogConflict y se revierte la operación completa; no se restaura silenciosamente en estado inconsistente. Esta entrega no incorpora restauración de categorías ni reasignación oculta de un producto eliminado.

### Contrato para Sales e historia

CatalogReader::forSale(actorId, productId) devuelve un SaleProductSnapshot readonly con productId, name, categoryId, categoryName y price vigentes, mediante una consulta de producto/categoría disponibles. Los datos son valores inmutables, no un modelo Eloquent vivo.

Sales deberá copiarlos al crear cada línea y será dueño de cantidad e importe. Las unidades ya agregadas conservarán nombre, categoría y precio registrados; nuevas incorporaciones usarán otra instantánea vigente. El agrupamiento de líneas deberá considerar esos valores, no solo productId: un cambio de precio, nombre o categoría no debe fusionar ni recalcular líneas previas. Ventas cerradas tampoco se recalculan con el catálogo actual. No se implementó Sales ni se creó dependencia Catalog → Sales.

### Referencia de imagen

ImageReference contiene disk lógico y key relativa **catalog/products/ULID.webp**, con identificador minúsculo inmutable. Se valida el formato, se rechazan URL/rutas absolutas/traversal y el servicio exige un disco configurado con visibilidad pública. No se guarda una URL final ni un binario; cambiar de almacenamiento exige mapear/migrar referencias de forma explícita, no modificar todos los precios/productos.

Un producto tiene como máximo una referencia; puede carecer de ella, sustituirla o retirarla. Un nuevo ULID de archivo permite invalidación de caché Android. No se realizan I/O, comprobación de existencia del archivo, subida ni eliminación física en esta etapa.

Para 1.4b: validar subida hasta 5 MB, inspeccionar formato/contenido, convertir a WebP, resolver URL con Laravel Filesystem, mantener la referencia hasta confirmar el reemplazo y retirar el archivo anterior solo cuando ya no tenga referencias. El hosting local y un futuro disco Azure Blob comparten el contrato disk/key; no se instaló adaptador Azure ni se conectó a ese servicio.

## D. Arquitectura y contratos

| Capa | Contenido |
| --- | --- |
| Domain | Price, CatalogText y CatalogConflict: invariantes y conflicto claro. |
| Contracts | CatalogAdministration, CatalogReader, snapshots readonly, ImageReference, ProductQuery, CategoryQuery y CatalogPage. |
| Application | ManageCatalog y CatalogInput; puertos específicos CategoryRepository/ProductRepository. |
| Infrastructure | Modelos Eloquent, repositorios concretos y consultas parametrizadas. |
| Policies | CatalogPolicy sobre UserSnapshot y permisos efectivos actuales. |
| Providers | Bindings DI y mapeo de CatalogConflict a CONFLICT existente. |

Las interfaces de repositorio están en Application/Ports y devuelven DTOs, no modelos privados. No son repositorios genéricos ni públicos para otros módulos. Los consumidores utilizan Contracts o el servicio de aplicación deliberadamente público; no acceden a Infrastructure ni abren transacciones independientes.

Servicios disponibles: crear/actualizar/activar/desactivar/consultar/listar categorías y eliminarlas lógicamente; crear/actualizar/cambiar precio/activar/desactivar/eliminar/restaurar/consultar/listar/buscar productos. Las búsquedas se expresan con ProductQuery/CategoryQuery, sin duplicar servicios para cada filtro.

Ejemplo de composición futura desde un adaptador protegido (actor obtenido del servidor):

```php
$catalog = app(\Modules\Catalog\Contracts\CatalogAdministration::class);
$page = $catalog->products($actorId, new \Modules\Catalog\Contracts\ProductQuery(
    name: 'cafe le', isActive: true, sort: 'price', page: 1, perPage: 20,
));
$snapshot = app(\Modules\Catalog\Contracts\CatalogReader::class)->forSale($actorId, $productId);
```

Las escrituras se coordinan con TransactionManager de Core. Reutilizan IdentityAccess::lock para que los cambios de permisos/estado de Users no se intercalen con una escritura autorizada; después se consulta la identidad actual y se bloquean entidades requeridas. Es la coordinación SQL existente, no otra infraestructura de sesiones/seguridad. Serializa estas escrituras: medir contención antes de ampliar carga; no se presenta como benchmark.

Los repositorios exigen transacción externa y no hacen commit/rollback propios. La auditoría se inserta síncronamente dentro de esa unidad. Un fallo revierte catálogo y auditoría juntos. No hay colas, outbox ni efectos secundarios de archivos que puedan deshacer operaciones ya confirmadas.

Matriz: **Catalog → Core/Users**, exclusivamente por contratos públicos; Audit → Core; Users → Core. Core no importa Catalog. No hay ciclos ni llamadas HTTP internas. No se agregó carpeta Http sin contenido ni controladores/rutas de catálogo.

## E. Búsquedas y paginación

ProductQuery admite nombre, categoryId, isActive nullable, deleted=without/with/only, sort=name/price, direction=asc/desc, page y perPage. CategoryQuery permite nombre, estado, eliminados y paginación; orden fijo por display_order/name/id.

Se normaliza el texto y se comparan nombres con **Latin1_General_100_CI_AI_SC**, sin modificar la collation de la base. Se verificaron búsquedas con acentos y mayúsculas en Express. La entrada se limita a cien unidades de almacenamiento y ocho palabras; cada palabra debe ser prefijo de palabra del nombre. La puntuación significativa se conserva; %, _ y [ se escapan como literales LIKE. Los valores se parametrizan; sort/direction/deleted son listas cerradas.

Se evita la coincidencia arbitraria de subcadenas: buscar afé no encuentra Café. Para prefijos de palabras internas se usa el patrón acotado % palabra%, que puede requerir un scan; el índice ayuda al prefijo inicial pero **no se promete un seek en toda búsqueda**. Es una decisión para un catálogo pequeño sin añadir infraestructura full-text. Revisar plan/volumen antes de cambiarla.

Páginas de 1–100 registros y máximo un millón de página; el orden añade id para desempatar. CatalogPage devuelve items/total/page/perPage. La estabilidad comprobada corresponde a un conjunto sin escrituras concurrentes; no se garantiza una instantánea transaccional entre varias páginas.

El filtro administrativo isActive=true refleja el estado del producto; no sustituye la comprobación de categoría disponible. El futuro POS debe usar forSale al agregar una unidad, no asumir que un listado administrativo autoriza comercialización.

## F. Seguridad y auditoría

Se reutilizan exactamente los seis permisos de catálogo existentes; no se modificaron roles ni se agregaron permisos.

| Operación | Permiso |
| --- | --- |
| Consultar/listar productos y obtener snapshot vigente | catalog.products.view |
| Crear producto | catalog.products.create |
| Actualizar nombre/descripción/categoría/imagen/precio | catalog.products.update |
| Activar/desactivar o eliminar lógicamente producto | catalog.products.deactivate |
| Restaurar producto | catalog.products.restore |
| Crear/editar/activar/desactivar/eliminar categoría | catalog.categories.manage |
| Consultar/listar categorías | catalog.products.view o catalog.categories.manage |

updateProduct con is_active exige además catalog.products.deactivate, evitando el bypass mediante edición genérica. Los actores deben existir, estar activos, no eliminados y haber completado el cambio obligatorio de contraseña. Los permisos proceden de Spatie mediante IdentityAccess; no hay bypass por nombre admin ni confianza en permisos de cliente. Los servicios reciben actor explícito para composición; 1.4b deberá tomarlo del guard del servidor, nunca de un ID proporcionado sin autenticar.

Se reutiliza SecurityEvent, ampliando su catálogo cerrado con eventos catalog.category.* y catalog.product.*. Audit conserva actor, entidad mediante event_type/record_id, UTC, resultado, cliente y request_id cuando existe. El subject_id de usuario no se usa para alojar un ID de producto.

Para el cambio mínimo relevante se añadió change_data nullable al registro existente: lista cerrada de changed_fields y, solo para product.price_changed, price_before/price_after canónicos. No se acepta payload libre, claves arbitrarias ni nombres/descripciones completos. El contrato rechaza datos indebidos antes del listener y SQL exige JSON válido. Los eventos previos siguen usando sus columnas originales; se probó compatibilidad antes de instalar la extensión y preservación de filas existentes al agregarla.

Eventos cubiertos: creación/actualización/estado/eliminación de categorías; creación/actualización/precio/estado/eliminación/restauración de productos. El JSON no incluye credenciales, PIN, tokens, consultas ni cadenas de conexión. No se creó otro listener, tabla de auditoría paralela ni API pública de auditoría. Se conserva la política de retención y el saneamiento de errores anteriores.

## G. Migraciones, respaldo y SQL Server

Migraciones nuevas revisadas y ejecutadas primero en fresapos_test:

1. Audit/2026_11_12_000004_add_catalog_change_data_to_security_events.php: agrega una columna nullable y CHECK ISJSON, sin eliminar/reemplazar filas ni columnas anteriores.
2. Catalog/2026_11_12_000005_create_catalog_tables.php: crea solo las dos tablas nuevas, índices, FK y CHECK. Rechaza tablas preexistentes antes de crearlas.

El down de ambas está restringido a bases cuyo nombre termina en _test. La fixture CatalogSqlServerTestCase hereda las salvaguardas existentes, crea los esquemas reales dentro de transacción y los retira en orden inverso. No usa SQLite, RefreshDatabase, migrate:fresh ni limpieza global. Las suites SQL se ejecutaron secuencialmente.

Antes de cambiar fresapos se creó **fresapos_pre_1_4a_20261112_165221Z_a1bb976f.bak**, a las 16:52:21 UTC. SQL Server confirmó archivo inexistente previamente, carpeta accesible fuera del repositorio y archivo existente después; BACKUP DATABASE con COPY_ONLY/CHECKSUM/NOINIT/NOFORMAT/STOP_ON_ERROR terminó correctamente. RESTORE VERIFYONLY WITH CHECKSUM confirmó lectura/conjunto/checksums y msdb verificó fresapos, copia independiente y tamaño de conjunto **5 849 088 bytes**. No se sobrescribieron ni eliminaron respaldos anteriores. No se afirma restauración de ensayo; la ubicación exacta permanece en .tools/catalog-1.4a-backup.json y en el directorio protegido Backup de la instancia.

Con respaldo verificado, suite completa y calidad correctas se ejecutó:

```powershell
.\scripts\local.cmd php artisan migrate --path=Modules/Audit/database/migrations/2026_11_12_000004_add_catalog_change_data_to_security_events.php --path=Modules/Catalog/database/migrations/2026_11_12_000005_create_catalog_tables.php --no-interaction
.\scripts\local.cmd php artisan migrate:status
```

Ambas quedaron **[4] Ran**. fresapos tiene quince tablas; las nuevas categorías/productos permanecen vacías, sin datos ficticios ni seeders operativos. No hubo cambios destructivos a Users/Auth ni recreación de permisos.

Compatibilidad real probada en SQL Server Express con ULID nchar(26), decimal(19,4), collation CI_AI_SC, FK NO ACTION, CHECK, JSON nullable, Eloquent y transacciones. Azure SQL utiliza mecanismos compatibles, pero su ejecución real, permisos, collation y hosting siguen pendientes; no se abrió una conexión no autorizada.

## H. Pruebas y calidad

| Comprobación | Resultado ejecutado |
| --- | --- |
| Línea base | 135 pruebas / 1256 aserciones, satisfactorias. |
| Primer conjunto focalizado Catalog | 62 pruebas / 241 aserciones, satisfactorias. |
| Suite completa tras ampliar cobertura | **200 pruebas / 1516 aserciones**, satisfactoria, 150,117 s. |
| Suite completa posterior a migración operativa | **200 pruebas / 1516 aserciones**, satisfactoria, 121,213 s y 82 MB; sin fallos, errores ni omisiones. |
| Pint | Passed. |
| Larastan | **Nivel 5, 162 archivos, sin errores**. |
| Composer validate --strict / check-platform-reqs | Correctos; dependencias conservadas. |
| Rutas | 23 propias, las mismas que antes; ningún endpoint nuevo de catálogo. |

Se agregaron **65 casos**: 37 de dominio/datasets en CatalogDomainTest y 28 de integración en CatalogTest. Cubren creación, actualización, unicidad Unicode/SQL, normalización, estados, validación de campos, decimales exactos, relaciones, categorías inválidas, instantáneas históricas, SoftDeletes/restauración, restricciones reales SQL, búsquedas/escapes, paginación/filtros, permisos actuales/sin bypass, referencias de imagen, auditoría mínima, rollback interno/externo y migración aditiva compatible con eventos anteriores.

Se verificó contra 0a0bc26 la conservación de los **109 métodos históricos**, incluidos sus datasets. No se eliminaron ni renombraron pruebas anteriores. El único cambio a una prueba previa amplía la expectativa de migraciones en ModuleRegistrationTest para exigir ambos archivos nuevos; no relaja rutas ni reglas arquitectónicas.

Primera ejecución estática: 26 diagnósticos sobre atributos mágicos Eloquent, tipo ULID e información genérica necesaria para scopes SoftDeletes. Se corrigieron declarando los atributos reales de los modelos y el tipo de Builder específico; no se agregaron @var ficticios, ignores, baseline ni casts para silenciar errores. Los tests focalizados y la primera suite completa no presentaron fallos. Pint ajustó formato/imports. Ningún problema se ocultó reduciendo Larastan.

Comandos reproducibles, en PowerShell desde la raíz:

```powershell
.\scripts\local.cmd composer test
.\scripts\local.cmd composer lint
.\scripts\local.cmd composer analyse
.\scripts\local.cmd composer validate --strict
.\scripts\local.cmd composer check-platform-reqs
.\scripts\local.cmd php vendor/phpunit/phpunit/phpunit --filter Catalog
.\scripts\local.cmd php artisan route:list --except-vendor
```

Las comprobaciones HTTP reales de cierre verificaron /, /up, /api/v1/ping, /login y JavaScript local Livewire con 200; páginas privadas con 302; API protegida con 401; login vacío con 422 y ruta exclusiva de tests ausente con 404. Los sobres JSON y X-Request-Id/meta.request_id coincidieron y el formulario conservó CSRF y snapshot Livewire. Se reutilizó el servidor existente de 8080, sin procesos duplicados.

La suite de integración utiliza exclusivamente fresapos_test mediante TestDatabaseGuard, verificando configuración y DB_NAME() antes de DDL. No hubo pruebas de catálogo, bloqueos, cambios de contraseña ni cuentas de prueba sobre fresapos.

## I. Integridad

Evidencia inicial/final privada de las filas completas: **doce tablas previas de datos idénticas**, incluyendo usuarios, contraseña/historial, seguridad y asignaciones. migrations añade únicamente las dos filas previstas; el registro Audit previo estaba vacío y la nueva columna es aditiva. La operación conserva un administrador activo, un usuario, un historial, cuatro roles, 44 permisos, 81 asignaciones rol-permiso y una asignación usuario-rol. No se ejecutó aprovisionamiento ni se tocaron credenciales operativas.

La comparación final confirmó nueve archivos protegidos intactos: .env, .env.testing, config/database.php, config/session.php, composer.json, composer.lock, phpstan.neon, phpunit.xml y Modules/Users/config/security.php. Se mantienen sus bytes y la conexión Windows existente. Los cambios deliberados fuera de Catalog se limitan al contrato/listener/extensión de auditoría, matriz de dependencias, expectativa de migraciones y documentación; Auth y sus rutas no se modificaron.

Las evidencias y logs de esta entrega están en .tools/catalog-1.4a-*, ignorado por Git; el backup queda fuera del repositorio. No se realizaron commits ni pushes. No se instaló Docker, Node, colas ni otro motor.

## J. Pendientes para 1.4b y despliegue

- Interfaz administrativa y adaptadores HTTP/API: validación de transporte, recursos, rutas protegidas y actor obtenido del guard. No se desarrollaron ahora.
- Subida de imágenes hasta 5 MB, conversión WebP, archivo público, resolución de URL, reemplazo/limpieza segura sin referencias e invalidación de caché Android por clave de archivo. Solo el contrato de referencia está preparado.
- Los futuros clientes no deben interpretar isActive administrativo como disponibilidad completa; deberán usar el contrato operativo al agregar productos.
- Sales deberá persistir sus instantáneas y separar líneas cuando cambien datos/precio. No se implementó negocio de ventas, cantidades o importes de líneas en Catalog.
- Validación real de Azure SQL/cPanel, adaptador Azure Blob, capacidades de procesamiento WebP, TLS/firewall y rendimiento bajo carga según despliegue. No se presentan como verificados.

No hay bloqueos locales pendientes para 1.4a. fresapos_test terminó con cero tablas de usuario. Se detiene esta entrega, sin commits/pushes ni avance automático a 1.4b.
