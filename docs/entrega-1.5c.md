# Entrega 1.5c — Interfaz POS Web

## A. Estado inicial

Inspección del 13 de noviembre de 2026; cierre de validación el **17 de noviembre** tras una pausa de la sesión: rama main, HEAD `2225a6d`, árbol inicialmente limpio. Se reutilizan dominio/persistencia de 1.5a, API/Resources/revisión de 1.5b, Catalog, sesión Web/PIN/CSRF, Policies/permisos, Audit y layout Blade. Laravel 13.42.0, PHP 8.3.36, Livewire 4.4.8 y SQL Server Express local. Sin reconstruir módulos.

Línea base ejecutada antes de editar: **363 pruebas / 3439 aserciones**, **4:36.723**, 110 MB; Pint correcto; Larastan nivel 5, 244 archivos, sin errores. Se tomaron hashes iniciales de las 18 tablas operativas y diez archivos protegidos. Cash, idempotencia durable, motivos, pagos y cierre financiero permanecían pendientes.

## B. Diseño

Blade + Livewire + Tailwind existente; CSS acotado al POS para distribución y controles táctiles, sin Node, Vite ni frontend separado. Escritorio 1440×900 y tablet horizontal 1024×768: catálogo/selección y panel lateral de venta. Tablet vertical 768×1024 y móvil 390×844: catálogo y botón inferior que abre panel desplazable, con Escape, devolución de foco y trampa de foco/inert mediante Alpine incluido en Livewire. Sin modales recortados ni scroll horizontal del documento.

Tarjetas de productos con imagen optimizada o sustituto de nombre, importes destacados y botones de al menos 44 px. Se conserva la identidad azul/slate y navegación administrativa existente; en /pos se evita duplicar la navegación de Catalog. Panel con errores accesibles también en móvil, labels, descripciones, foco visible y avisos live. No hay cálculos financieros en JavaScript.

## C. Navegación

Ruta **GET /pos**, nombre `sales.pos`, vista sales::pos y componente `sales.pos`/PointOfSale. Opción Punto de venta en el layout compartido, según permisos. Acceso exige sesión web vigente, usuario activo, contraseña cambiada, `sales.view` y `catalog.products.view`. Administración solo se ofrece con users.view; Mi cuenta reutiliza Auth para PIN/terminal/cambio de empleado. No hay otro sistema de login.

PosAccess se registra como middleware persistente de Livewire, además del EnsureWebAccess existente. Las acciones reautorizan identidad y permisos actuales en el servidor, sin depender de enlaces visibles o botones deshabilitados.

## D. Catálogo

CatalogAdministration y ProductQuery/CategoryQuery existentes: nombre normalizado, búsqueda parcial CI_AI_SC, categoría y páginas independientes de diez elementos. Paginación SQL, sin cargar todo el catálogo/categorías en memoria. Se añade únicamente `ProductQuery::availableForSale`, opt-in y false por defecto, para filtrar en SQL producto activo/no eliminado y categoría activa/no eliminada mediante whereHas. Los clientes anteriores y API conservan sus contratos y valores predeterminados.

Las imágenes se presentan desde ImageReference/disco público, con URLs admitidas, lazy loading y alt; no se exponen rutas físicas ni discos/keys como campos de estado. No se sustituye el precio histórico por el vigente. No existen controles de inventario/recetas. Cantidades y notas de incorporación son un formulario de intención; los importes y líneas siempre proceden de Sales, sin carrito paralelo.

## E. Mesas

Lista paginada de TableQuery: libre, ocupada o inactiva, conservando la disponibilidad derivada en SQL. Mesa ocupada permite consultar su venta; libre prepara la selección sin insertar venta; inactiva no permite apertura. Refresco explícito actualiza disponibilidad. No hay mapa, WebSockets ni tablas redundantes de ocupación.

## F. Ventas

Mostrador permite consultar abiertas/pausadas, con identificador, fecha UTC y total. Panel con tipo, nombre de mesa, estado, empleado activo, líneas históricas, cantidad, precio aplicado, importes, subtotal, descuento registrado, total y notas. Historial de líneas retiradas solo informativo.

Acciones disponibles bajo los contratos reales: apertura/agregado mediante CriticalSalesWrites, edición de cantidad absoluta o ±1, notas de línea/venta, pausa/recuperación mediante SalesOperations. La cantidad de formulario se valida como entero positivo de 1 al máximo configurado —100 inicial—, sin truncar decimales. Llegar a cero no retira una línea; exige el futuro flujo de motivo autorizado. Notas 100/150, normalización/texto plano por Sales; los borradores se conservan ante error y al refrescar la venta.

Se reutiliza CoordinateSalesWrite/SalesRevision: revisión bloqueada en el snapshot, comparación atómica y mismo lock/transacción que 1.5b. Cambios ajenos muestran aviso, impiden edición visible y requieren actualizar antes de reintentar; el servidor sigue rechazando acciones forzadas. No se reemplazan silenciosamente notas ni importes. Sin cierres, folios, cobros o descuentos financieros.

## G. Integración

PointOfSale presenta y coordina formularios sobre los contratos existentes. Sales calcula y audita una vez por cambio; Catalog entrega disponibilidad/precio/snapshot; Users identifica y autoriza; Core mantiene transacciones; Auth aporta sesión/PIN y protección de errores. No hay HTTP interno, API duplicada, cambios de permisos/roles ni dependencias circulares.

SalesAvailability solo informa de disponibilidad para deshabilitar controles. Consulta el contrato Cash dentro de una transacción coordinada cuando existe implementación y reconoce los adaptadores de rechazo existentes. Nunca habilita escritura ni reemplaza las guardas reales. No existe flag productivo para simular caja/idempotencia.

## H. Dependencias pendientes

Sin Cash real se muestra «La integración de caja está pendiente» y se deshabilitan apertura/edición. Sin idempotencia durable se bloquean apertura y agregado. Cancelar/retirar, cobrar/cerrar, descuentos especiales e imprimir ticket se muestran deshabilitados con explicación; no tienen handlers financieros nuevos. Motivos autorizados, pagos y cierre continúan pendientes. Ninguna selección genera ventas parciales.

Los únicos dobles de Cash/idempotencia son fixtures explícitos bajo Tests o el servidor privado de QA conectado exclusivamente a fresapos_test. No se registran en proveedores productivos ni se controlan desde parámetros del navegador.

## I. Seguridad

Se bloquean con #[Locked] actor de contexto, identificadores, DTO presentado, revisión, claves de idempotencia y objetivos de edición. Una modificación de precio/total/actor en el snapshot es rechazada. El servicio no recibe precios ni importes del navegador. Una línea ajena a la venta seleccionada no se edita. `sales.view` conserva su alcance operacional global; no se inventa propiedad por vendedor.

Tras cambio de empleado por PIN, un snapshot del empleado anterior recibe 403; el nuevo /pos monta identidad/permisos actuales. Las sesiones revocadas/inactivas se rechazan también en el protocolo real de Livewire. Se conserva CSRF de Laravel; sin token ni origen admitido se rechaza con 419, comprobado en PHPUnit con el middleware real activo. Laravel 13 también verifica Sec-Fetch-Site: un request same-origin válido puede pasar por verificación de origen aunque se altere su token. El sondeo negativo 419 en Chrome fue inconcluyente y no se contabiliza como aprobado; no se desactivó el middleware.

SensitiveErrors incluye /pos y sales.pos para impedir detalles internos con debug activo o inactivo. Validaciones muestran mensajes asociados; fallos de conexión/servidor preservan entradas y muestran mensaje genérico. El interceptor oficial de Livewire 4 presenta 401/419, cambio de permisos/empleado y fallos de red sin mostrar cuerpo de excepción. No se guarda PIN/token en JavaScript o almacenamiento local.

## J. Pruebas

Se añaden pruebas SQL/Livewire/protocolo real en PointOfSaleTest: navegación y permisos, Cash bloqueado, catálogos activos/búsqueda/categorías/páginas, imágenes/fallback, mesas, historia/importes, agregado/consolidación/repetición, cantidad/notas, pausa/recuperación, conflicto/refresco, línea ajena/cero, CSRF, revocación, inactividad, PIN/contexto, campos bloqueados, errores debug y estados terminales. Se mantienen todas las 363 anteriores.

Primera ejecución: error de compilación por un atributo @livewire:navigating interpretado como directiva Blade, corregido eliminando el atributo innecesario; también se corrigieron tres avisos Larastan de nullsafe redundante. Segunda: dos expectativas de prueba, valor de textarea a comprobar mediante estado Livewire y entero PDO devuelto como string. Tercera: **16 pruebas / 197 aserciones**, 42.806 s, 60 MB, correctas. Se añaden después casos de mutación forzada sin Cash y estados vacíos, incluidos en la suite completa final. No se eliminan pruebas ni se silencian errores.

La primera suite completa se interrumpió por timeout de Composer de 900 segundos durante la pausa del 13 al 17; **no se contabiliza como aprobada**. Al reanudar se comprobó que no quedaba PHPUnit activo, se inventariaron los fixtures interrumpidos y se retiraron exclusivamente de fresapos_test mediante la salvaguarda y whitelist existentes. El log fallido se conserva en `.tools/sales-1.5c-final-tests.log`; no se cambió el timeout global ni se debilitó ninguna prueba.

Comandos desde PowerShell en la raíz:

```powershell
.\scripts\local.cmd php vendor/bin/phpunit tests/Integration/PointOfSaleTest.php
$previousTimeout = $env:COMPOSER_PROCESS_TIMEOUT
try {
    $env:COMPOSER_PROCESS_TIMEOUT = '900'
    .\scripts\local.cmd composer test
} finally { $env:COMPOSER_PROCESS_TIMEOUT = $previousTimeout }
.\scripts\local.cmd composer lint
.\scripts\local.cmd composer analyse
.\scripts\local.cmd composer validate --strict
.\scripts\local.cmd composer check-platform-reqs
.\scripts\local.cmd composer openapi
.\scripts\local.cmd php artisan route:list --except-vendor
.\scripts\local.cmd php artisan migrate:status
```

| Comprobación final real | Resultado |
| --- | --- |
| composer test, ejecución reanudada | **381 pruebas / 3653 aserciones**, **5:41.752**, **112 MB**, sin fallos/errores/omisiones |
| Diferencia respecto a la línea base | **+18 pruebas / +214 aserciones**; se conservan las 363 anteriores |
| composer lint | Passed |
| composer analyse | **Nivel 5, 253 archivos**, sin errores; baseline 244 |
| composer validate --strict / check-platform-reqs | Correctos, sin nuevas dependencias |
| OpenAPI formal y correspondencia con rutas | **42 operaciones**, cero errores/advertencias; contratos REST intactos |
| Contraste OpenAPI con respuestas de regresión | **138 respuestas reales / 32 operaciones Catalog+Sales**, cero errores/advertencias |
| route:list --except-vendor | **58 rutas propias**, baseline 57; se agrega /pos |
| migrate:status | Seis migraciones existentes, última Sales en lote 5 Ran; sin cambios |
| HTTP nativo /, /up, /api/v1/ping, /login, JS Livewire | **200** |
| /pos, /account y Catalog sin sesión | **302 al login**; acceso autenticado/PIN comprobado en SQL/Livewire y navegador QA |
| Base de pruebas al terminar | **Cero tablas** |

No se modifican contratos REST; se contrasta OpenAPI igualmente como regresión. Resultados en `.tools/sales-1.5c-final-tests-resumed.log`, `sales-1.5c-quality-resumed.json`, `sales-1.5c-response-report.json` y logs asociados.

## K. Revisión visual

Se intentó computer-use y falló el kernel por inicialización del sandbox de Windows. Se utilizó **Chrome 157.0.8103.51 headless real** mediante DevTools, con JavaScript/Livewire activos. No se confunde con HTTP/render estático. Servidor QA 127.0.0.1:8083, perfil de Chrome propio y conexión operativa excluida, credenciales/sesiones/archivos/mesas/ventas exclusivamente de fixture.

Matriz de **24 capturas**: mostrador, catálogo, WebP, venta, mesas y búsqueda vacía a 1440×900, 1024×768, 768×1024 y 390×844. Cero errores JavaScript, sin desbordamiento horizontal; imagen WebP cargada, trampa de foco comprobada en ambas vistas móviles y apertura táctil en 390. Hallazgos corregidos: etiquetas Mostrador/Limpiar partidas, navegación demasiado alta y visibilidad de errores dentro del panel móvil. Evidencia en docs/qa/1.5c.

Interacciones adicionales: **cinco capturas**, total **29**: guardado con controles deshabilitados, error de nota con texto conservado, nota guardada, conexión offline y sesión revocada real. Dos clics sobre cantidad dejaron cantidad 3, total exacto 161.5000 y **un solo evento sales.quantity_changed** en SQL de fixture. No se presenta esto como idempotencia durable.

En la primera comprobación de revocación, Livewire 4 redirigía al login porque su petición no pedía JSON. Se añadió Accept: application/json únicamente al interceptor del POS; la comprobación posterior recibió el rechazo de sesión y mostró el aviso, conservando el formulario. La sesión estuvo protegida también antes del ajuste. Cero errores JavaScript, desbordamientos o botones visibles menores de 44 px en las 29 mediciones. La revisión visual no sustituye una auditoría completa de accesibilidad.

El primer seed visual rechazó una key de imagen fuera del patrón SQL; se limpió solo la fixture guardada en fresapos_test y se usó el patrón válido, sin relajar CHECKs. El primer selector CSS del sondeo se corrigió conforme al DOM. El sondeo 419 de navegador queda inconcluyente; el caso 419 de PHPUnit con protocolo real sí pasó. Servidor QA y Chrome se cerraron, y la base de pruebas se limpió antes de la regresión completa. Capturas y reportes sin secretos en [docs/qa/1.5c](qa/1.5c/README.md).

## L. Integridad

Sin migraciones nuevas, tablas de carrito ni cambios en la migración de 1.5a. Datos/configuración comparados mediante hashes de filas de las 18 tablas —incluida migrations— y diez archivos: .env, .env.testing, database/session/filesystems, composer.json/lock, phpstan.neon, phpunit.xml y seguridad Users. **Diez archivos protegidos idénticos byte a byte**, incluido Windows Authentication; usuarios/credenciales, cuatro roles y 44 permisos conservados.

La comparación histórica del 13 al 17 muestra **15 tablas idénticas y tres con diferencias**: audit_security_events, auth_access_sessions y catalog_products. La auditoría operativa registra ocho eventos web entre 20:29 y 20:37 UTC del día 13: dos accesos, cierres/revocaciones y dos actualizaciones de productos. Audit pasó de 15 a 23 filas, accesos de 3 a 5 y el catálogo sigue con dos productos. Se conservó esa actividad operativa; no se restauraron filas ni se atribuye igualdad histórica a las 18 tablas. Los fixtures/navegador de QA utilizaron exclusivamente fresapos_test.

Desde la primera comprobación al reanudar hasta el cierre: **18/18 tablas idénticas**, sin tablas nuevas. Se conservan un usuario/administrador activo, dos categorías y dos productos; **cero ventas, líneas o mesas operativas**. Las pruebas no introdujeron registros en fresapos. Evidencia: `.tools/sales-1.5c-historical-differences.json`, `sales-1.5c-integrity-comparison.json` y `sales-1.5c-integrity-resumed-comparison.json`.

Las pruebas y QA usan fresapos_test con TestDatabaseGuard y conexión operativa eliminada en memoria. Se retiran fixtures, servidor y perfil en uso de QA al finalizar; no se usa migrate:fresh/db:wipe, no hay seeds ni ventas sobre fresapos. No se hacen commits/pushes. Evidencias privadas en .tools/sales-1.5c-* y .tools/pos-visual; solo capturas/reportes sin secretos pasan a docs.

## M. Pendientes

Cash real/sesión abierta, idempotencia durable, catálogos de motivos, cobros, cierre, folios/tickets y descuentos financieros. Integración real de Azure SQL/cPanel con ODBC/pdo_sqlsrv, TLS/firewall, permisos y PHP/ionCube, pendiente con credenciales autorizadas. Sin inventario, recetas, Android, WebSockets ni avance a 1.5d.

**ETAPA 1.5c: ACEPTADA LOCALMENTE**. POS Web integrado y responsivo, servicios existentes reutilizados, 381 pruebas satisfactorias, calidad conservada y 29 capturas reales con controles de seguridad. Cash/idempotencia/motivos siguen pendientes y protegidos. Datos operativos actuales y configuración conservados, con la actividad histórica indicada en L. Sin commits/pushes ni decisiones de autorización pendientes para este alcance. La entrega se detiene aquí, sin iniciar 1.5d.
