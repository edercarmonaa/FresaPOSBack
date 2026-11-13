# Entrega 1.4d — Validación integral y cierre local de Catalog

Fecha: **13 de noviembre de 2026**. Entorno: Windows/PowerShell, PHP 8.3.36, SQL Server Express `17.0.1155.3`, instancia `.\AUDITORIA`, autenticación integrada de Windows y aplicación en `http://127.0.0.1:8080`.

**ETAPA 1.4d: ACEPTADA LOCALMENTE**. Revisión visual completada posteriormente en Chrome headless real, con los cuatro tamaños solicitados, capturas y acciones JavaScript/Livewire. Azure/cPanel permanecen pendientes de despliegue. La revisión local no certifica dispositivos físicos ni conformidad WCAG completa.

## A. Línea base

Se inspeccionaron Git, los informes [1.4a](entrega-1.4a.md), [1.4b](entrega-1.4b.md) y [1.4c](entrega-1.4c.md), documentación y código antes de modificar archivos. Rama `main`, HEAD inicial `5cf3dd1` (`Add integration tests for catalog API and web functionality`). Había cambios de 1.4c sin confirmar, incluidos procesamiento de imágenes y sus pruebas. Se conservaron; el diff inicial quedó en evidencia privada para distinguirlos de 1.4d. No se hicieron commits ni pushes.

La primera ejecución de `composer test` fue interrumpida por el límite de Composer de 300 segundos. **No fue una ejecución satisfactoria ni una prueba fallida por aserciones**. Se verificó que no quedaba PHPUnit ejecutándose; la interrupción había dejado 16 tablas de fixtures en `fresapos_test`. Un helper privado comprobó ambiente de pruebas, conexión efectiva y nombres conocidos, ejecutó los desmontajes existentes y retiró exclusivamente esas tablas de prueba. No se utilizó `migrate:fresh` ni `db:wipe`, ni se escribió en `fresapos`.

La repetición, ampliando el timeout exclusivamente en el proceso y restaurándolo después, estableció la línea base real:

| Control | Línea base ejecutada |
| --- | --- |
| PHPUnit completo | **268 pruebas, 2209 aserciones**, 256,972 s, 92 MB; correcto |
| Pint | Correcto |
| Larastan | Nivel **5**, **195 archivos**, sin errores |
| Base operativa | 15 tablas, un usuario, un administrador activo; cero categorías/productos |

La evidencia de la interrupción y de la repetición se conserva en `.tools/catalog-1.4d-baseline-tests.log` y `.tools/catalog-1.4d-baseline-retry.log`. Las evidencias privadas están ignoradas por Git.

## B. Auditoría técnica

Se revisaron modelos Category/Product, migraciones, contratos y DTOs, repositorios Eloquent, `ManageCatalog`, coordinador de imágenes, procesador GD, almacenamiento/journal, proveedores, rutas, controladores, Form Requests, Resources, Policies, permisos, componentes Livewire y Blade, integración con Audit y pruebas existentes. No se reconstruyó Catalog ni se cambiaron sus reglas comerciales.

Los contratos existentes separan aplicación, dominio e infraestructura. Core no depende de Catalog y Catalog no depende de Sales. Los servicios coordinan permisos y transacciones; los repositorios no abren transacciones independientes. Las pruebas de arquitectura siguen pasando.

Se encontraron dos defectos reproducibles: pérdida de `no-store` al renderizar errores JSON fuera del middleware y falta de asociación programática entre errores e inputs de formularios. Se corrigieron con pruebas. La especificación OpenAPI existente era válida; faltaba incorporar y ejecutar un validador formal reproducible. No fue necesario modificar esquemas públicos, reglas de negocio, tablas o matriz de permisos.

## C. OpenAPI

Archivo validado: **`docs/openapi.yaml`, OpenAPI 3.1.0**. Se comprobó que no había un validador formal disponible y se reutilizó Python **3.12.14** instalado. Se creó un entorno virtual de desarrollo en `.tools/openapi`, sin Node/Docker y sin nuevas dependencias Composer o de hosting.

Herramientas: **openapi-spec-validator 0.9.0** para la especificación completa y **openapi-schema-validator 0.9.0** para respuestas reales. Sus dependencias transitivas están fijadas en `scripts/requirements-openapi.txt`. Se eligieron por soporte explícito de OpenAPI 3.1 y mantenimiento del proyecto [oficial](https://github.com/python-openapi/openapi-spec-validator), con [documentación](https://openapi-spec-validator.readthedocs.io/en/latest/).

Preparación desde PowerShell en la raíz, indicando un ejecutable Python 3.12 existente:

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File scripts/setup-openapi.ps1 -PythonPath RUTA_AL_PYTHON_EXE
.\scripts\local.cmd composer openapi
```

`composer openapi` invoca `scripts/validate-openapi.ps1`. El exportador PHP obtiene las rutas del router real de Laravel sin consultar la base. El validador Python rechaza claves YAML duplicadas, aplica el validador formal 3.1, compara método/path y revisa la declaración Bearer y middleware `auth:sanctum`/`auth.access.api` de Catalog. Se excluye HEAD automático de Laravel de la comparación con las operaciones explícitas de la documentación.

Para contrastar también los cuerpos reales, ejecutar primero la suite completa o la clase nueva:

```powershell
.\scripts\local.cmd php vendor/bin/phpunit tests/Integration/CatalogAcceptanceTest.php
powershell.exe -NoProfile -ExecutionPolicy Bypass -File scripts/validate-openapi.ps1 -Responses .tools/catalog-1.4d-contract-responses.json -Report .tools/catalog-1.4d-openapi-report.json
```

El segundo comando también se ejecutó después de la suite final. Las muestras son respuestas de Catalog con fixtures, sin respuestas de login ni tokens. La validación utiliza JSON Schema/OpenAPI 3.1 y su comprobador de formatos; no se limita a parsear YAML.

| Comprobación final | Resultado real |
| --- | --- |
| Especificación formal 3.1.0 | PASS |
| Operaciones API documentadas frente a Laravel | **25 coincidentes**, 15 de Catalog |
| Rutas implementadas sin documentar / documentadas inexistentes | **0 / 0** |
| Respuestas contrastadas con sus esquemas | **34**, cubren las **15 operaciones** de Catalog |
| Errores / advertencias del validador | **0 / 0** |

Se revisaron parámetros, entradas, ULID, respuestas, códigos, errores y operaciones de imagen contra Requests/Resources y pruebas HTTP. Las respuestas muestreadas no equivalen a cubrir todas las combinaciones posibles de datos/estados. Las otras diez operaciones API se incluyen en validación formal y correspondencia de rutas; las 34 muestras son exclusivamente de Catalog.

Controles negativos sobre copias privadas: quitar `info` de la especificación produjo un error formal y salida no satisfactoria; convertir un precio de respuesta a número fraccionario produjo un error de esquema. Ambos resultados fueron los esperados y no alteraron los archivos válidos. Informes: `.tools/catalog-1.4d-negative-spec-report.json` y `.tools/catalog-1.4d-negative-response-report.json`.

## D. API REST

Las pruebas existentes y el nuevo ciclo HTTP verifican las 15 operaciones de Catalog: listado, consulta, alta, actualización, estado y borrado de categorías; listado, consulta, alta, actualización, estado, borrado lógico y restauración de productos; subida y eliminación de imagen.

Se comprobaron normalización/unicidad de categorías, relaciones, categorías inactivas/eliminadas, precios exactos como texto de cuatro decimales —incluido el máximo `999999999999999.9999`—, cambios de precio, filtros, búsquedas, orden, páginas, papelera y restauración atómica. La categoría conserva el orden por prioridad/nombre/id; no se inventaron opciones de ordenamiento.

Las respuestas mantienen `success`, `code`, `message`, `data`, errores cuando corresponden y `meta.request_id`; listados incluyen paginación. Se prueban estados 200/201 y errores relevantes 401/403/404/409/422, además de la regresión previa de errores genéricos. El identificador `X-Request-Id` se conserva. Los errores JSON ahora incluyen `Cache-Control: no-store, private`. Las pruebas previas de excepción ocultan SQL, rutas y detalles tanto con debug activo como inactivo.

Seguridad cubierta por pruebas SQL/HTTP/Livewire: anónimo; administrador; supervisor, cajero y mesero con capacidades limitadas; permiso retirado; usuario inactivo/eliminado o con cambio de contraseña pendiente; sesión/token revocados; ULID inexistente/manipulado; producto borrado; rutas/acciones administrativas directas; intentos de alterar campos bloqueados, filtros o columnas SQL. No se concedieron permisos adicionales. Se mantienen CSRF, Policies y comprobación de capacidades en el servidor.

## E. Livewire

Se ejecutaron los tests de `/admin/catalog/products` y `/admin/catalog/categories`: render y navegación autorizada, formularios, validaciones, conservación de valores, edición/precio/estado, borrado/restauración y conflictos, búsqueda/filtros/paginación, permisos y manipulación de identificadores. El protocolo HTTP real de Livewire dentro del kernel prueba CSRF, sesión revocada y permiso retirado. Las pruebas de imágenes verifican subida temporal/vista previa protegidas, creación, sustitución, retirada y conservación de campos ante error.

Inicialmente se inspeccionaron confirmaciones `wire:confirm`, controles de carga, botones deshabilitados, estados vacíos y alternativa por nombre sin imagen mediante código/tests. La revisión complementaria de F ejecutó además esos escenarios en Chrome: filtros, paginación, formularios, validación, carga/vista previa, doble clic, confirmación cancelada/aceptada y papelera/restauración.

Comprobaciones GET reales al servidor local existente:

| Recurso | Resultado |
| --- | --- |
| `/`, `/up`, `/api/v1/ping`, `/login` | **200** |
| JavaScript Livewire indicado por el HTML de login | **200**, 609665 bytes |
| Ambas pantallas administrativas sin sesión | **302** a login |
| Listados API de productos/categorías sin token | **401** |

La URL del script usa el prefijo real generado por Livewire (`/livewire-ba99715c/livewire.js?...`). Una sonda inicial a la ruta supuesta `/livewire/livewire.js` dio 404; se corrigió la comprobación usando el HTML real, sin modificar rutas de la aplicación. Login/logout y administración de sesiones se verificaron en la suite con fixtures, sin iniciar sesión con credenciales operativas.

## F. Revisión visual

**Completada en navegador real, modo headless.** El navegador interactivo continuó fallando; se utilizó el Chrome instalado mediante su interfaz oficial de línea de comandos y Chrome DevTools Protocol. No se automatizó la interfaz de Windows ni se instalaron Node, Docker o extensiones.

| Resolución solicitada | Estado |
| --- | --- |
| 1440 × 900, escritorio | Productos/categorías, listados, edición/errores, acciones, paginación y vacíos comprobados |
| 1024 × 768, tablet horizontal | Mismos escenarios comprobados |
| 768 × 1024, tablet vertical | Mismos escenarios comprobados |
| 390 × 844, móvil | Mismos escenarios, más teclado, subida/vista previa, carga/guardado y confirmaciones |

El fallo inicial de la skill `computer-use:computer-use` fue `windows sandbox failed: helper_unknown_error: setup refresh had errors`; se repitió al reintentar. Era un fallo de inicialización, no una denegación de permiso. La alternativa usó **Chrome/157.0.8103.51**, protocolo 1.3, Python 3.12 y **websocket-client 1.9.2** en el entorno privado `.tools/visual-qa/python`. La versión 1.10.0 anunciada por la documentación no estaba disponible en el índice consultado; se instaló 1.9.2 disponible. No cambió Composer ni el runtime PHP. Referencias oficiales: [Chrome headless](https://developer.chrome.com/docs/automation-and-testing/headless), [CDP Emulation](https://chromedevtools.github.io/devtools-protocol/tot/Emulation/) y [websocket-client](https://websocket-client.readthedocs.io/en/latest/).

Se levantó un servidor QA efímero en **127.0.0.1:8082**, usando el código y vistas reales, únicamente la conexión `sqlsrv_testing` validada por `TestDatabaseGuard`, usuario de prueba y 13 categorías/31 productos iniciales. Sesiones, cache, imágenes y perfil Chrome se aislaron en `.tools/visual-qa`. Tras validar el destino SQL, las peticiones HTTP utilizaron el comportamiento nativo local de CSRF/subidas, evitando los fakes unitarios de Livewire. No se usaron credenciales ni sesiones del administrador operativo.

Se guardaron **44 capturas PNG**: 36 de la matriz visual y ocho de interacciones adicionales, con sus informes JSON en [docs/qa/1.4d](qa/1.4d/README.md). Son píxeles renderizados por Chrome con CSS y JavaScript reales, no HTML estático. Los tamaños son viewports CSS con escala 1; no se afirma probar hardware móvil, teclado virtual o gestos táctiles físicos.

Resultados: sin desbordamiento horizontal de la página, sin superposición observada, imágenes cargadas y formularios/botones accesibles mediante desplazamiento vertical. En 390 px las tablas conservan desplazamiento horizontal **dentro de su contenedor**, comprobado hasta las acciones de ambas pantallas. La tabla de productos mide 660 px de contenido en 309 px de contenedor; no ensancha la página. En escritorio y ambas tablets no necesitó ese desplazamiento para el fixture revisado. Los nombres largos se ajustan en las celdas; los inputs/selects conservan el desplazamiento/truncamiento nativo de sus valores extensos.

Se comprobó paginación de productos adelante/atrás y búsquedas vacías en los cuatro tamaños; edición con imagen, listados con/sin imagen y error visible de categoría duplicada. En móvil se verificó error de precio con valores conservados, carga con indicador, vista previa privada y guardado con mensaje. Dos clics consecutivos durante una respuesta ralentizada dejaron el botón deshabilitado y **exactamente un producto** en SQL, con precio `42.5000`. Se canceló una confirmación de borrado y se aceptaron desactivar/activar/borrar/restaurar mediante los diálogos nativos de JavaScript, registrados en `confirmations.json`.

Los escenarios finales no registraron excepciones JavaScript ni errores en las respuestas fetch observadas. Durante la preparación se corrigieron exclusivamente el harness privado: clave de aplicación de fixture, ejecución HTTP nativa para subidas y esperas de la secuencia asíncrona. No se atribuyen esos errores de preparación al producto ni se cuentan como ejecuciones satisfactorias. No fue necesario cambiar código de la aplicación por la revisión visual.

Al terminar se cerraron Chrome de QA y el servidor 8082, se desmontaron las 14 tablas temporales de `fresapos_test` mediante los down existentes y se repitió la comparación de integridad: cero tablas de prueba y las quince tablas operativas idénticas al inicio.

## G. Accesibilidad

Revisión estática: etiquetas de campos, nombres de botones, caption de tablas, texto alternativo en imágenes, texto por nombre sin imagen, avisos `role=alert/status` y controles de carga existentes. Las confirmaciones actuales utilizan `wire:confirm`; no se añadieron modales o un sistema visual nuevo.

Defecto corregido: el resumen de errores era visible, pero cada input carecía de vínculo programático con su error. Se añadieron IDs, `aria-invalid`, `aria-describedby` y un parcial `catalog::field-error` con mensajes por campo y `aria-live=polite`. Se contemplan aliases entre campos Livewire y validación del dominio (`categoryId/category_id`, `displayOrder/display_order`, `isActive/is_active`); se conservan las ayudas de precio e imagen.

Dos pruebas DOM sobre HTML real generado por Livewire comprueban precio inválido y nombre obligatorio de categoría: input marcado inválido, referencia al nodo correcto y mensaje no vacío. La revisión complementaria verificó Tab desde navegación hasta Crear producto, apertura con Enter y Tab al textarea, con foco visible capturado; también confirmó errores asociados en el navegador y los diálogos descritos en F.

Se midieron colores computados convertidos a sRGB sobre fondos opacos de **105 muestras de texto/controles habilitados** del listado: contraste mínimo **6,42:1**, ninguna por debajo de 4,5:1. Es una muestra de esa pantalla/estado, no una auditoría de todos los estados, placeholders, componentes nativos o modos de alto contraste. Informes y foco en [la evidencia visual](qa/1.4d/README.md). Lector de pantalla y conformidad WCAG integral no se certifican.

## H. Imágenes

Se reutilizaron y ejecutaron los **43 casos de 1.4c** dentro de la regresión: 25 de procesamiento/contenido y 18 de integración. Cubren JPEG/PNG/WebP, salida WebP, proporciones, transparencia, ausencia de escalado innecesario, ocho orientaciones EXIF con comprobación de píxeles y retirada de metadatos. Se rechazan SVG/GIF/animaciones, archivos vacíos/corruptos, ejecutables renombrados, MIME/nombre falsificados, más de 5 MiB y dimensiones/presupuesto de memoria peligrosos.

Se comprueban claves seguras, URLs/versiones nuevas, sustitución y retirada; fallo de procesamiento, escritura parcial, actualización/auditoría y eliminación del archivo previo. La recuperación mediante journal/reintento conserva referencias compartidas y evita borrar el archivo vigente. Rollback de producto nuevo más imagen, SoftDeletes, rechazo de transacciones externas y retirada idempotente siguen pasando.

El nuevo ciclo de aceptación también verifica versiones distintas y un solo evento de auditoría por alta/sustitución/retirada. Las imágenes de prueba usan discos falsos y directorios de testing. El disco público operativo contiene únicamente `.gitignore` y las reglas `.htaccess` de 1.4c, sin imágenes ficticias añadidas por esta etapa.

Los originales temporales/vistas previas requieren sesión y permisos; las imágenes finales son públicas por diseño. Se prueban el endpoint temporal y sus permisos, no un mecanismo de archivos finales privados. La recuperación ante fallos inyectados está probada; una interrupción real de proceso, el cron de limpieza y los handlers Apache requieren verificación de despliegue.

## I. SQL Server

Las integraciones se ejecutaron sobre **`fresapos_test`**, sin SQLite y con salvaguarda de nombre/conexión. Se verifican ULID `nchar(26)`, claves foráneas reales, unicidad e índices existentes, precios `decimal(19,4)`, SoftDeletes y referencias históricas. Las transacciones mantienen commit/rollback y auditoría coordinada; un fallo no deja cambios parciales.

Las consultas usan bindings y listas permitidas para orden/campos; la paginación se hace en SQL con tamaños acotados. La búsqueda escapa literales LIKE. La presentación reutiliza categorías por identificador dentro de la petición; existe coste adicional acotado por categorías distintas de la página, sin consulta por cada producto repetido en la misma categoría. No se afirma un benchmark de carga ni optimización para latencia Azure: queda para medición con volumen/entorno representativos.

Comparación inicial/final de **todas las filas completas de las quince tablas**: ordenadas y resumidas con SHA-256, **sin diferencias**. Incluye usuario, contraseña/historial, roles/permisos, sesiones, auditoría y migraciones. Evidencia privada: `.tools/catalog-1.4d-integrity-before.json`, `catalog-1.4d-integrity-final.json` y `catalog-1.4d-final-state.json`.

Estado final: **un usuario y un administrador activo**, cinco migraciones, lote de Catalog **4**, cero categorías/productos operativos —igual que al inicio— y **cero tablas restantes en `fresapos_test`**. No se introdujeron ni aplicaron migraciones en 1.4d, no se crearon fixtures operativos y no se restableció el administrador.

Nueve archivos protegidos permanecen idénticos byte a byte: `.env`, `.env.testing`, `config/database.php`, `config/session.php`, `config/filesystems.php`, `composer.lock`, `phpstan.neon`, `phpunit.xml` y `Modules/Users/config/security.php`. En `composer.json` solo se agregó el script `openapi`, comprobado mediante comparación estructural con HEAD; requisitos/autoload/configuración no cambiaron.

## J. Auditoría

Los tests previos cubren creación/modificación de categoría y producto, precio, activación/desactivación, borrado/restauración e imágenes, además de rollback cuando Audit falla. Se conserva el esquema cerrado de metadata, sin texto libre o secretos.

El nuevo ciclo verifica exactamente **un evento** por `image_added`, `image_replaced`, `image_removed`, `price_changed`, `deleted` y `restored`; actor/entidad correctos, resultado `success`, request ID y ausencia de contraseña en el registro. Con reloj fijado a las 12:00 de Ciudad de México, las marcas almacenadas corresponden a **18:00 UTC**, 13 de noviembre de 2026. No se duplicó la auditoría en controladores o componentes.

## K. Calidad

Comandos ejecutados desde PowerShell y la raíz del repositorio:

```powershell
# Timeout solo del proceso; restaurar el valor anterior al terminar.
$previousTimeout = $env:COMPOSER_PROCESS_TIMEOUT
try {
    $env:COMPOSER_PROCESS_TIMEOUT = '900'
    .\scripts\local.cmd composer test
} finally {
    $env:COMPOSER_PROCESS_TIMEOUT = $previousTimeout
}
.\scripts\local.cmd composer lint
.\scripts\local.cmd composer analyse
.\scripts\local.cmd composer validate --strict
.\scripts\local.cmd composer check-platform-reqs
.\scripts\local.cmd composer openapi
git -c safe.directory=C:/Repos/FresaPOSBack diff --check
```

| Control | Resultado final real | Comparación |
| --- | --- | --- |
| PHPUnit completo | **273 pruebas / 2454 aserciones**, **188,557 s**, **94 MB**, correcto | +5 pruebas, +245 aserciones |
| Pint | Correcto | Conserva línea base |
| Larastan | Nivel **5**, **197 archivos**, sin errores | +2 archivos, sin rebajar nivel/ignores |
| Composer validate --strict | Correcto | Sin cambio de requisitos ni lock |
| Composer check-platform-reqs | Correcto | Plataforma local compatible |
| OpenAPI formal y rutas | Correcto, 0 errores/0 advertencias | Nuevo control reproducible |
| Contraste de respuestas OpenAPI | 34 respuestas/15 operaciones, correcto | Nuevo control |
| git diff --check | Correcto | Sin errores de whitespace |

No se eliminaron pruebas ni se redujeron expectativas. La suite final no reporta fallos, errores u omisiones. Se conservan pruebas de módulos, persistencia, Core/API, usuarios, login/logout/PIN/sesiones/seguridad y Catalog de las etapas anteriores. Los tiempos son resultados de estas ejecuciones locales, no un benchmark de rendimiento comparativo.

La clase nueva, `tests/Integration/CatalogAcceptanceTest.php`, contiene cinco casos: ciclo API con muestras contractuales y auditoría; supervisor/token revocado; snapshot disponible e imagen opcional para futuro Sales; asociación de error de precio y de nombre obligatorio. Ejecución focalizada final: **5 pruebas / 245 aserciones**, 9,487 s.

Antes de corregir los defectos, la ejecución focalizada produjo **4 fallos entre 5 pruebas, 42 aserciones**. Después pasó completa; se conservan ambos logs. El fallo intencional de los controles negativos OpenAPI y la interrupción inicial por timeout se documentan, no se ocultan como ejecuciones satisfactorias.

Evidencia: `.tools/catalog-1.4d-final-tests.log`, `catalog-1.4d-final-analyse.log`, `catalog-1.4d-acceptance-before.log`, `catalog-1.4d-acceptance-tests.log`, `catalog-1.4d-openapi-report.json`, `catalog-1.4d-http.json` y `catalog-1.4d-livewire-asset.json`.

## L. Correcciones y archivos de 1.4d

1. **Errores JSON cacheables:** la excepción se renderizaba fuera de `auth.no-store`; el filtrado de cabeceras del factory no preservaba esa política. `Modules/Core/app/Http/ApiResponseFactory.php` establece `no-store, private` en sus respuestas de error. Se conserva el sobre, código HTTP y cabeceras permitidas; no se cambia el render HTML/Livewire. El arreglo pertenece al factory común, sin introducir dependencia Core → Catalog.
2. **Errores sin asociación accesible:** cambios puntuales en `Modules/Catalog/resources/views/livewire-products.blade.php` y `livewire-categories.blade.php`, más `field-error.blade.php`. Se conservan acciones, reglas y layout.
3. **Validación formal faltante:** nuevos `scripts/setup-openapi.ps1`, `requirements-openapi.txt`, `export-api-routes.php`, `validate-openapi.py` y `validate-openapi.ps1`; nuevo script Composer y prueba de aceptación. La instalación Python permanece en `.tools` y no va al hosting.
4. **Documentación:** este informe, README, CHANGELOG, `docs/api.md` y descripción de `docs/openapi.yaml`. No se cambió el contrato para forzarlo a pasar.

Los demás cambios de imágenes/almacenamiento que aparecen en el working tree proceden de 1.4c y se conservaron. No se añadieron tablas, permisos, funciones comerciales o dependencias PHP en 1.4d.

## M. Preparación para Sales

Contrato existente **`CatalogReader::forSale(actorId, productId)`**, resuelto por el contenedor, devuelve `SaleProductSnapshot` inmutable con **`productId`, `name`, `categoryId`, `categoryName`, `price`**. Una lectura satisfactoria exige producto y categoría disponibles en ese momento; los no disponibles producen `CatalogConflict` conforme a las reglas existentes. El snapshot no contiene un flag adicional de disponibilidad ni imagen.

La prueba conserva una copia, cambia nombre/categoría/precio y confirma que la copia anterior no cambia, mientras una nueva lectura refleja los valores actuales. También verifica que desactivar la categoría impide otra lectura para venta. No demuestra persistencia de líneas históricas de Sales porque ese módulo aún no se implementa.

Para la referencia opcional de imagen y estado propio existe **`CatalogAdministration::product` → `ProductSnapshot`**, con `image`, `isActive` y `deletedAt`; `ImageReference` identifica disk/key y Resources/presentación generan URL/version mediante Filesystem. Esta lectura administrativa **no sustituye** la comprobación de elegibilidad de `forSale`, y las capacidades del actor siguen siendo obligatorias. Se documentan contratos actuales sin ampliarlos para inventar necesidades futuras.

El futuro Sales será responsable de copiar y persistir nombre/categoría/precio y calcular cantidad/importes de sus propias líneas; los cambios posteriores del catálogo no deben reescribirlas. Una lectura disponible no reserva stock ni garantiza disponibilidad futura. No se implementaron tablas de ventas, mesas, cobros, servicios financieros o inventario, ni una dependencia Catalog → Sales.

## N. Pendientes

- **Dispositivos/accesibilidad ampliada:** hardware táctil y teclado virtual reales, otros motores de navegador y lector de pantalla. Los cuatro tamaños, foco/teclado, contraste muestreado, acciones, carga y capturas de Chrome sí se comprobaron; no se afirma conformidad WCAG integral.
- **Azure SQL:** conexión autorizada, cifrado/certificados/firewall, permisos efectivos, comportamiento y latencia con datos representativos. No se conectó a Azure ni se usaron credenciales remotas.
- **cPanel/Apache:** PHP 8.3/ionCube, `pdo_sqlsrv`/`sqlsrv` y ODBC, GD WebP/EXIF, memoria/límites de subida, HTTPS, permisos, vínculo público, reglas `.htaccess`/AllowOverride/handlers y cabeceras reales. No se certifica compatibilidad por analogía con Windows.
- **Operación de archivos:** supervisión/cron del journal y temporales, respaldo coherente SQL+archivos+journal y recuperación ante caída real de proceso. Los escenarios de fallo inyectado sí pasan.
- **Frontend y carga:** resolver Tailwind CDN histórico antes de producción conforme a `docs/frontend.md`, y medir consultas/volumen/latencia en entorno representativo. No se instaló Node/Vite.

No se requiere una decisión de diseño o cambio de alcance para cerrar la aceptación local documentada. Los pendientes anteriores requieren herramientas/entornos de verificación y no se presentan como completados.

## O. Estado final

**ETAPA 1.4d: ACEPTADA LOCALMENTE**. Evidencia: 273 pruebas/2454 aserciones; Pint, Larastan nivel 5 y Composer correctos; OpenAPI formal, rutas y respuestas sin errores ni advertencias; dos defectos reproducibles corregidos; integridad operativa inicial/final idéntica y base de pruebas vacía.

La revisión visual local queda **completada**, con 44 capturas y los límites descritos en F/G. Azure/cPanel quedan pendientes de despliegue. No se identificaron defectos críticos abiertos en el alcance comprobado. Se conserva el administrador y la configuración Windows/SQL Server. No hubo commits/pushes y el trabajo se detiene en 1.4d, sin avanzar a Sales.
