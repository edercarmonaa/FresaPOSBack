# FresaPOSBack — Entrega 1.4c: imágenes del catálogo

Fecha: 12 de noviembre de 2026, America/Mexico_City.

**ETAPA 1.4c: ACEPTADA localmente.** Suite completa, calidad, publicación HTTP e integridad comprobadas. No se inicia 1.4d.

## A. Estado inicial

Git limpio sobre **5cf3dd1**. Se leyeron las entregas 1.4a/1.4b y se inspeccionaron Catalog, contratos, servicios, permisos, auditoría, transacciones Core, repositorios, Form Requests, Resources, rutas, Livewire, Filesystem, almacenamiento público, PHP, pruebas y archivos protegidos. La entrega 1.4b ya estaba implementada: no se reconstruyó.

La línea base real pasó con **225 pruebas / 1974 aserciones**, 171,986 s, 88 MB; Pint correcto y Larastan **nivel 5, 186 archivos**, sin errores. Entorno conservado: Windows/PowerShell, PHP 8.3.36, Laravel 13.42.0, Livewire 4.4.8, Sanctum 4.4.0, SQL Server Express `.\AUDITORIA` y Windows Authentication. fresapos tenía quince tablas, un administrador activo y catálogo vacío.

Existían image_disk/image_key, ImageReference, ProductSnapshot y ProductResource con URL/versión, disco public y convención **catalog/products/ULID.webp**. No existían procesamiento, subida, limpieza ni enlace public/storage. GD/EXIF estaban deshabilitados, aunque las DLL ya formaban parte del PHP portátil. Límites PHP iniciales: upload_max_filesize=2M, post_max_size=8M y memory_limit=128M.

## B. Implementación y componentes reutilizados

- Puertos Application: ImageProcessor y ProductImageFiles; ImageChange expresa una intención privada de cambio.
- Infrastructure/Images: GdImageProcessor procesa el contenido; FilesystemProductImages encapsula archivos finales y journal con Laravel Filesystem.
- Application/Services/ManageProductImages coordina proceso, autorización, transacción, asociación, compensación y recuperación. Reutiliza CatalogAdministration, ProductRepository, IdentityAccess::lock, CatalogPolicy y TransactionManager, sin duplicar reglas de producto/categoría/precio.
- ProductRepository añade referencesImage, con implementación Eloquent que incluye SoftDeletes y exige transacción. No es un repositorio genérico ni expone modelos a otros módulos.
- ProductController añade uploadImage/removeImage; ProductImageRequest valida transporte. Dos rutas nuevas POST/DELETE `/api/v1/catalog/products/{id}/image`.
- Products Livewire mantiene su pantalla y formulario, añade WithFileUploads, selección/vista previa/cancelación/sustitución/eliminación. No se creó otro panel.
- ReconcileProductImages incorpora `catalog:images:reconcile`, simulación por defecto y `--apply` explícito.
- Se amplía el catálogo cerrado de SecurityEvent; auditoría sigue en ManageCatalog/Audit. No se añade otra infraestructura de auditoría.
- Registro DI, configuración de imágenes, límites de subidas, publicación local, pruebas, OpenAPI y documentación actualizados.

No se modifican los contratos públicos de lectura, campos del ProductResource, precios, categorías, estados, ventas ni reglas de restauración.

## C. Validación y procesamiento WebP

Configuración técnica inicial en Modules/Catalog/config/config.php, configurable en el despliegue sin nuevas preguntas funcionales:

| Parámetro | Valor |
| --- | --- |
| disk | public |
| journal_disk | local, privado |
| max_bytes | **5242880 bytes (5 MiB)** |
| max_pixels | 8 000 000 píxeles de entrada |
| max_input_dimension | 12 000 píxeles por lado |
| max_dimension | 1200 píxeles en el lado mayor de salida |
| quality | 82 |
| max_processing_bytes | 192 MiB de presupuesto estimado |
| recovery_age_hours | 24 horas |

El límite de archivo alimenta Form Request, subida temporal y validación Livewire; el procesador confirma el número real de bytes. Los límites de transporte se expresan en KiB, y el contenido se vuelve a comprobar con el límite exacto en bytes. Se admiten JPEG/PNG/WebP por contenido real: finfo, cabecera/dimensiones, estructura mínima y **decodificación GD completa**. Ni extensión ni MIME declarado por el cliente autorizan el formato. Un PNG válido con nombre engañoso se normaliza a WebP; un ejecutable con nombre JPEG se rechaza.

Se rechazan vacíos, SVG, GIF, archivos comprimidos/ejecutables, cabeceras no compatibles, JPEG truncado sin terminador, PNG sin cierre, WebP con longitud RIFF inconsistente, PNG animado/WebP animado y errores del decodificador. Se verifican dimensiones/píxeles antes de decodificar. Las advertencias de contenido inválido se convierten en errores públicos de validación, con restauración del manejador PHP; los fallos operativos de proceso siguen propagándose como error interno seguro, no se ocultan.

El presupuesto estima píxeles × 16, buffers de entrada y margen, y lo compara también con memoria disponible de PHP. No se afirma que un límite PHP por sí solo proteja toda la memoria nativa de GD. El número de píxeles/lado y el presupuesto son límites adicionales. No se descomprimen ZIP ni se invocan herramientas del sistema con entradas del cliente.

Se aplican las **ocho orientaciones EXIF**, incluidos reflejos; luego se copian los píxeles a un canvas nuevo con transparencia y se codifica una vez a WebP. Se preserva proporción, sin ampliar imágenes pequeñas. No se arrastran EXIF/GPS/comentarios ni otros metadatos del original. También se verifica que la salida sea WebP válido y no vacía. Editar campos de un producto sin subir otra imagen no reprocesa el archivo; no se recodifica reiteradamente el WebP almacenado.

GD y EXIF nativos bastan; no se instaló una librería Composer, Imagick ni ejecutable externo. Se habilitaron sus DLL en el php.ini portátil y se actualizó setup-local.ps1 para reproducirlo. Valores PHP locales finales: **8M/16M/256M** para upload/post/memory. Se reinició únicamente el servidor PHP de este repositorio en 8080 y volvió a responder. `.env` y SQL Server no se reconfiguraron. Composer conserva sus requisitos previos; GD/EXIF/WebP se comprueban explícitamente con PHP y mediante las pruebas.

Referencias técnicas: PHP advierte que [getimagesize no basta como validador](https://www.php.net/manual/en/function.getimagesize.php); se combina con [decodificación](https://www.php.net/manual/en/function.imagecreatefromstring.php). Se comprueba el resultado de [imagewebp](https://www.php.net/manual/en/function.imagewebp.php) además de su valor de retorno.

## D. Almacenamiento, publicación y URLs

Una imagen principal opcional por producto. Se reutilizan disk/key, sin URL rígida, rutas absolutas persistidas, binarios SQL ni galerías. Convención anterior conservada: **catalog/products/ULID.webp**; no se cambia a la organización sugerida por producto porque ya había un contrato aprobado. El nombre original nunca determina el destino. Cada nueva versión genera otro ULID y se comprueba que no exista antes de escribir, bajo coordinación SQL.

Filesystem genera la URL según el disco; ProductResource conserva image={url,version} o null. La versión es el ULID del archivo. Se exige disco público y URL HTTP(S) o relativa al origen. Las claves se validan mediante ImageReference, sin rutas arbitrarias del cliente. Cambiar a Azure Blob requerirá instalar/configurar un adaptador compatible y migrar/mapear referencias; no cambiar el servicio del catálogo. No se conectó a Azure ni se instaló un adaptador sin uso.

Publicación Windows: scripts/publish-catalog-storage.ps1 comprueba primero el destino real y el estado de public/storage. Si existe otra carpeta, enlace o reglas distintas se detiene sin sobrescribirlos. Se creó una junction únicamente hacia storage/app/public y se verificó la repetición idempotente. `.gitignore` excluye public/storage para evitar versionar archivos publicados.

Se prepararon reglas Apache desde Modules/Catalog/resources/storage/.htaccess en la carpeta local de imágenes: sin índices/CGI, denegar nombres diferentes de ULID.webp, handler estático y MIME WebP, nosniff y caché inmutable cuando mod_headers esté disponible. PHP integrado no interpreta .htaccess; su efectividad en Apache/cPanel debe validarse con AllowOverride/módulos del hosting, sin alterar ese servidor aquí. La aplicación solo publica WebP regenerado y jamás originales o scripts. No se promete proteger una configuración externa que fuerce ejecución indebida de extensiones estáticas.

El enlace fue probado por HTTP 200 con una **sonda textual aleatoria**, comprobación del contenido y retirada inmediata en finally. No se creó una imagen de prueba en el almacenamiento operativo ni un producto real. La prueba de binarios WebP utiliza discos falsos. No se publica storage/app/private ni su journal.

## E. Sustitución, eliminación y recuperación

1. Verificar actor/permisos y existencia del producto; validar/procesar antes del lock costoso.
2. Abrir transacción raíz y tomar el lock de coordinación existente de Users, revalidando identidad/permisos. Bloquear producto y recuperar su imagen actual.
3. Registrar intención privada antes de escribir: journal catalog-image-journal/ULID.json, con fecha y referencias nueva/anterior; sin binario ni credenciales.
4. Escribir nuevo WebP sin sobrescribir y asociarlo mediante CatalogAdministration::updateProduct. Auditoría dentro de la transacción.
5. Tras commit, comprobar referencias SQL de ambas claves bajo el mismo lock y retirar únicamente archivos que ya no use ningún producto, **incluidos eliminados lógicamente**. Cerrar el journal si la limpieza termina.

Si falla el proceso no hay archivos nuevos. Si falla persistencia/auditoría, SQL revierte y se limpia la nueva clave solo tras comprobar que no quedó referenciada. El archivo anterior permanece. Si una escritura de almacenamiento fue parcial antes de fallar, el journal ya existía y permite recuperarla. Si falla eliminación/journal/SQL durante la limpieza, se conserva el registro pendiente y se escribe un aviso con ID técnico y clase de excepción, sin mensajes privados, SQL o rutas. No se propaga una limpieza secundaria para aparentar que se revirtió un commit ya confirmado.

El coordinador de archivos **rechaza entrar dentro de una transacción externa** antes de hacer I/O. Core no ofrece callback de rollback externo, por lo que aceptar esa composición podría borrar archivos o perder compensaciones. La operación de formulario «crear producto con imagen» se coordina en una sola transacción raíz mediante saveProduct; no deja un producto creado si la imagen o auditoría fallan. La recuperación también se niega a borrar archivos dentro de una transacción externa.

Recuperación controlada, ejecutada primero en pruebas:

```powershell
.\scripts\local.cmd php artisan catalog:images:reconcile
.\scripts\local.cmd php artisan catalog:images:reconcile --apply
```

El primer comando solo enumera intenciones de más de 24 horas; el segundo reconsulta SQL y aplica limpieza segura. No acepta rutas de archivo del operador/cliente ni barre el disco borrando archivos desconocidos. Los registros inválidos detienen el comando para revisión; no habilitan eliminación arbitraria. Un corte después de escribir, antes/después del commit o durante la limpieza conserva una intención recuperable. No se instaló una cola ni un cron; configurar su ejecución operativa controlada al desplegar, sin aplicar comandos de limpieza sobre datos reales durante esta validación.

No es una transacción distribuida: un archivo y SQL no se revierten mutuamente. El journal aporta recuperación ante interrupciones; requiere disco privado persistente, acceso a SQL y supervisión. El lock serializa referencias y limpieza frente a los servicios existentes; el I/O de escritura dentro del lock puede añadir latencia en un almacenamiento remoto, que deberá medirse en Azure.

Eliminar imagen conserva producto y campos; sin imagen es idempotente y no duplica auditoría. Enviar producto a papelera **no elimina su archivo**; al restaurarlo conserva referencia/versión. Los archivos compartidos tampoco se retiran mientras otro producto los referencia. Las modificaciones físicas deben pasar por ManageProductImages; el contrato anterior de referencias sin I/O sigue disponible para compatibilidad interna y no es un cargador de archivos.

## F. Android y caché

La referencia pública mantiene image.url e image.version. Sustituir genera otra clave y URL; no sobrescribe el mismo recurso. Clientes deben refrescar el recurso del producto y usar la URL/versión nueva, sin cerrar sesión. Retirar devuelve image=null y la UI conserva el recuadro con nombre del producto. Las imágenes públicas finales pueden tener caché larga/inmutable; las respuestas JSON y vistas previas temporales permanecen privadas/no-store. No se implementó Android, descarga offline, sincronización ni invalidación mediante notificaciones.

## G. Seguridad y experiencia administrativa

Se reutilizan Sanctum y los permisos actuales: API/servicio exigen **catalog.products.view y catalog.products.update**. Crear con imagen exige también create; crear sin imagen conserva el permiso anterior. No se crearon permisos ni se ampliaron roles. Se verifica producto existente y no eliminado; no se aceptan actor, disk, key, URL o nombres de destino. Catalog no posee dueño/tenant por producto: edición autorizada es global al catálogo, sin inventar un filtro de propiedad. La limpieza protege claves referenciadas por otros productos.

Controladores/componentes no procesan imágenes ni generan auditoría. ManageCatalog registra image_added/image_replaced/image_removed una sola vez, con actor, record_id de producto, UTC, request_id y changed_fields=['image']; sin original/binary/GPS. La actualización exclusivamente de imagen usa su evento específico, sin duplicar product.updated. El resto de cambios conserva los eventos anteriores.

Livewire restringe _startUpload/_finishUpload a una sola propiedad image, sin cargas múltiples. La ruta temporal y la vista previa firmadas exigen además sesión vigente y permiso de edición; las respuestas usan no-store privado y nosniff. El disco temporal es local privado, incluso si el disco final cambia en el futuro. Estas reglas globales de uploads corresponden al único flujo de archivos actual; deberán separarse si otros módulos incorporan cargas distintas. No se altera login ni la administración de sesiones previa.

El formulario admite seleccionar imagen al crear/editar, vista previa firmada, descartar selección, reemplazar al guardar y eliminar con confirmación. El guardado se bloquea mientras Livewire procesa una solicitud; sus valores se conservan al validar. Quitar imagen actúa solo sobre la imagen y advierte que no guarda los demás campos editados. Los identificadores/URLs auxiliares están Locked. Si no hay imagen, sigue el recuadro con nombre; no se añadió una imagen genérica.

El original solo existe como temporal privado de Livewire para selección/vista previa y proceso; se intenta retirar al descartar/cerrar/guardar correctamente. Si falla esa retirada, se registra un aviso y queda la limpieza de temporales antiguos del framework al realizar subidas posteriores, normalmente de más de 24 horas. Planificar limpieza/supervisión de temporales en despliegues sin tráfico; no se conserva un archivo original permanente ni una copia pública de él. No se prometen pruebas visuales/JavaScript en un navegador real que no se ejecutaron.

Errores: 422 para contenido/límites; 401/403 para sesión/permiso; 404 para producto/ULID; 500 seguro para fallos internos, reutilizando Core y el sanitizador Catalog existente. PHP/proxy tienen límites adicionales y pueden rechazar el cuerpo antes de la aplicación; no se afirma que una respuesta generada por un hosting externo tenga el sobre JSON.

## H. SQL Server y esquema

**Sin migraciones nuevas ni cambio del esquema**. Los campos existentes bastan, incluida versión derivada del ULID; no hay tabla de galerías ni almacenamiento binario. Se conservan nchar(26), decimal(19,4), FK y auditoría existentes. No hubo migrate/fresh/wipe ni cambio de autenticación Windows, `.env`, credenciales o administración real.

Integración exclusivamente en fresapos_test con CatalogSqlServerTestCase/TestDatabaseGuard: configuración y DB_NAME() se verifican antes del DDL y se elimina la conexión operativa del contenedor de pruebas. Discos public/local falsos para archivos. La base de pruebas no se sustituye por SQLite. Al no existir DDL operativo no se necesitó aplicar una migración ni crear un respaldo nuevo para esta entrega.

## I. Pruebas y evidencia

Comandos reales desde PowerShell/raíz del repositorio:

```powershell
.\scripts\local.cmd composer test
.\scripts\local.cmd composer lint
.\scripts\local.cmd composer analyse
.\scripts\local.cmd composer validate --strict
.\scripts\local.cmd composer check-platform-reqs
.\scripts\local.cmd php vendor/bin/phpunit --filter CatalogImage
.\scripts\local.cmd php --ri gd
.\scripts\local.cmd php --ri exif
.\scripts\local.cmd php artisan route:list --except-vendor
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\scripts\publish-catalog-storage.ps1
```

| Comprobación | Resultado final real |
| --- | --- |
| composer test | **268 pruebas / 2209 aserciones**, 192,046 s, 92 MB; sin fallos, errores, avisos ni omisiones. Conserva las 225 anteriores. |
| composer lint | Passed. |
| composer analyse | **Larastan nivel 5, 195 archivos, sin errores**, frente a 186 de línea base. |
| composer validate --strict / check-platform-reqs | Correctos; Composer y dependencias intactos. |
| PHP GD/EXIF | GD con JPEG/PNG/WebP y EXIF activos; memoria 256M, subida 8M, post 16M. |
| SQL Server | Integración sobre fresapos_test; desmontaje final con cero tablas. |
| route:list --except-vendor | **40 rutas propias**, dos nuevas; 25 operaciones API totales. |
| OpenAPI/rutas/referencias | 25 operaciones coincidentes y 304 referencias resueltas; validación formal YAML/OpenAPI pendiente. |
| Publicación/HTTP | Servidor 8080 operativo; enlace público con sonda textual 200, contenido exacto y retirada comprobada. |
| Integridad | Quince tablas operativas y diez archivos protegidos sin diferencias, un administrador activo, catálogo real vacío. |

Se añadieron **43 casos**: 25 de contenido/procesamiento y 18 de integración SQL/API/Livewire. Cubren formatos y WebP, MIME/nombre falsificado, vacíos/corruptos/SVG/GIF/animación, 5 MiB y byte adicional, límites de píxeles/memoria, proporciones/transparencia, ocho orientaciones **por colores de esquinas y dimensiones**, eliminación de metadatos, claves/URL/versiones, permisos/sesión, rechazo de rutas del cliente, sustitución/eliminación, referencias compartidas/SoftDeletes, auditoría, rollback de producto+archivo, fallos parciales de almacenamiento, limpieza fallida/reintento, transacción externa, formulario/vista previa, valores conservados y límites de Livewire.

Las 225 pruebas anteriores conservan sus métodos y datasets. Solo se amplía la lista exacta de API con las dos rutas nuevas; no se rebajan checks de arquitectura, seguridad, migraciones o nivel estático. Pruebas focalizadas previas: **43 casos / 209 aserciones**, antes de reforzar la prueba de orientación por colores; procesamiento reforzado: **25 casos / 78 aserciones**, satisfactorio. La primera suite completa detectó dos reflejos EXIF incorrectos (4 y 7), que se corrigieron manteniendo las expectativas y verificando las ocho variantes. La suite completa se repite tras corregirlos.

Durante implementación se corrigió la retención del objeto UploadedFile mientras se leía su temporal, validación de truncado JPEG y helpers de cookies/consola. No se instaló Mockery: las pruebas de comandos usan Artisan::call real. No se silenciaron diagnósticos de Larastan ni se añadieron ignores/baseline.

HTTP real de cierre: `/`, `/up`, `/api/v1/ping`, `/login` y JavaScript local Livewire respondieron **200**; administrador de productos **302** sin sesión. El formulario y subidas autenticadas se verifican con fixtures de pruebas, sin credenciales operativas. Publicación del enlace: 200 y contenido exacto de sonda textual, eliminada después.

OpenAPI existente actualizado con las dos rutas multipart/eliminación y ProductResponse original. Comprobación de correspondencia y referencias: **25 operaciones API, 15 de Catalog y 304 referencias internas**, sin duplicados ni rutas ficticias. Son 40 rutas propias totales. La validación sintáctica YAML y formal del esquema OpenAPI sigue **pendiente**, como en 1.4b; no se afirma haber usado una herramienta ausente ni se instala una sin necesidad.

## J. Integridad y configuración

La comparación inicial/final de **todas las filas completas de las quince tablas**, ordenadas y resumidas mediante SHA-256 en evidencia privada, coincidió sin diferencias. Incluye usuario/contraseña/historial, administrador, roles, permisos/asignaciones, sesiones, auditoría y migrations. Se conserva un usuario y un administrador activo, cinco migraciones (las dos de 1.4a en lote 4) y cero categorías/productos operativos. No se usaron las credenciales del administrador ni se aprovisionaron cuentas reales.

Diez archivos protegidos idénticos byte a byte: **.env, .env.testing, config/database.php, config/session.php, config/filesystems.php, composer.json, composer.lock, phpstan.neon, phpunit.xml y Modules/Users/config/security.php**. fresapos_test quedó con cero tablas. La verificación final de su contenido fue de solo lectura con conexión efímera a la misma instancia; no alteró los archivos ni la conexión operativa.

Almacenamiento operativo final inspeccionado: solo .gitignore y las reglas .htaccess preparadas, sin imágenes o journal de prueba. La sonda textual se retiró inmediatamente. Los archivos de imagen de las pruebas pertenecen a discos falsos en el área de testing, separados del disco público operativo.

Cambios de entorno deliberados: solo php.ini portátil privado (GD/EXIF y límites), su receta versionada setup-local.ps1, junction pública comprobada y reglas estáticas locales. No hay nuevas dependencias Composer ni cambio de motor, Docker, Node, Vite, colas, Azure o cPanel. Las evidencias y logs están en .tools/catalog-1.4c-*, ignorados por Git; no contienen credenciales operativas publicadas. No se realizaron commits ni pushes.

## K. Pendientes para despliegue y 1.4d

- Validar en cPanel/Apache GD con WebP, EXIF, memoria/subidas, permisos de disco, HTTPS, vínculo público, AllowOverride, handlers estáticos y cabeceras reales. No se alteró su configuración operativa.
- Validar Azure SQL y futuro adaptador Azure Blob con credenciales autorizadas, URL HTTPS, metadatos/visibilidad y latencia bajo lock. No se presenta como integrado o probado en Azure.
- Programar/supervisar recuperación del journal privado y limpieza de temporales en servidores sin tráfico. Conservar respaldo conjunto de SQL, archivos finales y journal; probar recuperación ante interrupción real del proceso además de los fallos inyectados.
- Validación formal YAML/OpenAPI con herramienta adecuada; las referencias/rutas comprobadas no la sustituyen.
- Revisión visual de JavaScript/navegador/tablet y cierre integral de 1.4d; las verificaciones actuales son procesamiento, HTML/acciones, protocolo HTTP y SQL reales.
- Resolver antes de producción el Tailwind CDN histórico conforme a docs/frontend.md, sin instalar Node/Vite en esta entrega.

No se implementa inventario, galerías, ventas, tickets ni cliente Android. La entrega termina en **1.4c**.
