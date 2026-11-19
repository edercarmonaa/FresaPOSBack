# Entrega 1.6b — Descuentos autorizados y cierre de total cero

19 de noviembre de 2026. **ETAPA 1.6b: ACEPTADA LOCALMENTE**. Regresión de 705 pruebas/8212 aserciones, respaldo verificado y migración incremental aplicada. Sin despliegues externos.

## A. Diagnóstico inicial

Proyecto existente, rama `main`, HEAD inicial `87f7656`, árbol limpio. PHP 8.3.36, Laravel 13.42.0, trece módulos y SQL Server Express `.\AUDITORIA` con autenticación Windows. Se inspeccionaron informes anteriores, contratos, permisos, PIN, persistencia, transacciones, Checkout, Cash, Core, Audit, rutas, Livewire, pruebas y OpenAPI. No se reconstruye ningún módulo ni se añaden dependencias.

La línea base ejecutada fue **641 pruebas / 7504 aserciones**, 19:29.200, 184 MB, exit 1: `SaleReasonsConcurrencyTest@rollback` no observó el marcador `holding` dentro de su barrera de 15 segundos. El mismo caso, con código heredado intacto, pasó aisladamente: **1 prueba / 15 aserciones**, 7.720 segundos, exit 0. No se atribuye una causa no demostrada ni se declara exitosa esa ejecución completa. Pint y Larastan pasaron. Las 641 pruebas previas se conservan; la regresión final debe comprobarlas nuevamente.

Se reutilizan `DiscountProposal`, `DiscountApproval`, `SalesOperations`, `CriticalSalesWrites`, `DurableSalesWrites`, `CoordinateSalesWrite`, `TransactionManager`, `DecimalAmount`, los recursos y revisiones de Sales, las sesiones Cash, métodos de pago, folios, `IdentityAccess`, Policies y auditoría central. Los fixtures históricos que deliberadamente no instalan la migración de descuentos mantienen un adaptador de aprobación ausente exclusivo de pruebas.

## B. Dominio

Una venta admite **un solo descuento vigente**, sin acumulación. Se mantienen los códigos ya existentes `percent` y `fixed`. `percent` exige un entero JSON entre 1 y 100; `fixed` exige texto decimal positivo, hasta cuatro decimales y sin superar el subtotal. No se admiten floats, cantidades negativas, cero fijo, porcentajes fraccionarios, cupones, descuentos por línea ni promociones.

Solo se edita una venta `open`, sin folio ni pagos confirmados y con su sesión Cash original abierta. El ciclo de vida previo no permite editar `paused`: primero debe reanudarse. Ventas cerradas, pagadas o canceladas son historia inmutable. Aplicar sobre subtotal cero se rechaza. La retirada de todas las líneas puede dejar un porcentaje registrado con importe cero, pero esa venta vacía no se puede cerrar por Checkout.

## C. Cálculos

`SaleTotals::calculate` es la única fórmula compartida por mutaciones Sales, vista previa y Checkout. Suma líneas históricas activas y verifica `unit_price × quantity = line_total`. Importes `decimal(19,4)` y strings canónicos de cuatro decimales, sin floats ni paquetes aritméticos nuevos.

Porcentaje: `discount = subtotal × percent / 100`, con **redondeo único al final, escala cuatro, mitades hacia arriba**. `DecimalAmount::percentage` multiplica y divide dígitos de texto; el producto intermedio puede exceder 19 dígitos sin desbordar PHP. `total = subtotal − discount`. Los límites máximos y mitades se contrastan con SQL Server real. Ejemplos: `0.0005 × 10 % = 0.0001`; `999999999999999.9999 × 99 % = 989999999999999.9999`.

Al agregar productos, cambiar cantidad o retirar líneas, el porcentaje se recalcula conservando tasa y autorización original. El fijo conserva su importe; si la reducción del subtotal lo invalida, se revierte toda la mutación. Se exige sustituir o quitar expresamente el descuento antes de reducir el consumo. Nunca se recorta ni elimina silenciosamente.

## D. Autorización

Las cuatro autorizaciones reales son `sales.view`, `sales.open.update`, `sales.discounts.apply` y `sales.special.authorize`. Administrador y supervisor las tienen; cajero y mesero no. No se cambian roles ni permisos operativos. Actor y fecha UTC los asigna el servidor, sin contraseña maestra ni credenciales nuevas.

El cambio por PIN reutiliza `EmployeeAccess`, sus sesiones y sus límites. Los componentes bloquean su contexto de actor y rechazan el anterior después del cambio. Además, la revalidación de sesión/token se ejecuta **después de obtener el bloqueo de identidad y antes del replay o escritura** mediante el contrato público `Auth\Contracts\AuthenticatedRequestAccess`. Esto evita que una solicitud previamente validada herede el acceso de una sesión revocada mientras esperaba. El parámetro opcional `access` de `CriticalSalesWrites` conserva a los consumidores internos existentes.

La nueva relación `Sales → Auth` existe únicamente para el contrato de acceso en los adaptadores HTTP/Livewire; se declara en `config/module-dependencies.php`, sin ciclos ni importación de modelos internos. Las reglas de negocio siguen recibiendo actor e identidad por contratos. No se almacena PIN en descuentos, intenciones, auditoría ni documentación de evidencia.

La revalidación bajo bloqueo es **solo lectura**. El middleware conserva la actualización normal de actividad. Se probó expresamente el cambio de segundo entre middleware y autorización: actualizar nuevamente la actividad dentro de la transacción retenía la fila de sesión y retrasaba la respuesta de una clave concurrente. El caso determinista falló antes de la corrección (1 prueba/5 aserciones, 30.142 s) y pasó después (1/19, 5.514 s), conservando `OPERATION_IN_PROGRESS`. El reloj fijo sin ese cruce no exponía la espera.

## E. Aplicación y sustitución

`ManageSales::setDiscount` aplica o sustituye dentro de la transacción existente, bajo las mismas exclusiones de identidad, Cash, venta y revisión. El descriptor, autor, UTC y tres totales se actualizan atómicamente. Sustituir calcula sobre el subtotal vigente, sin aplicar un descuento sobre otro. Nueva autorización reemplaza el descriptor actual y conserva la anterior en Audit.

## F. Eliminación

DELETE utiliza los mismos cuatro permisos, UUID v4 y revisión. Elimina solo el descriptor vigente y restaura `discount_amount=0`, `total=subtotal`. Audit conserva antes/después y el usuario que quitó el descuento. No elimina ventas, líneas, pagos ni historia. Quitar un descuento inexistente no inventa una aprobación ni un evento financiero.

## G. Integración Checkout

Checkout vuelve a calcular los totales con `SaleTotals`, compara lo persistido y exige `AuthorizedDiscounts` cuando existe descriptor, incluso si un porcentaje pequeño redondea a cero. El autorizador original debe seguir activo y conservar los cuatro permisos al cobrar; si perdió autorización, se exige una nueva aprobación. El cajero puede cobrar un descuento aprobado por un supervisor válido sin adquirir facultad para modificarlo.

Los pagos se asignan al total descontado mediante el calculador previo. Se prueban pago mixto y cambio: venta 180, descuento 20 %, total 144; efectivo aplicado 44/recibido 50, tarjeta 100 y cambio 6. Un replay de cierre ya confirmado no vuelve a ejecutar reglas financieras ni exige reinterpretar una aprobación histórica; siempre reautoriza al actor ejecutante según Core.

Una prueba de integración adicional crea supervisor y cajero con sus roles reales: el primero autoriza, el segundo cobra 144 y el cierre conserva al autorizador. Retirar después el rol del supervisor permite recuperar el cierre ya confirmado por la misma intención, sin nuevos pagos ni auditoría. La pérdida de autorización antes de un cobro nuevo sí lo bloquea.

## H. Ventas de cero pesos

Porcentaje 100 y fijo igual al subtotal positivo producen un **cierre real** `closed`, con folio diario y fecha UTC, mediante Checkout y `payments=[]`. No hay pago de cero, efectivo recibido, cambio, ingreso manual ni motivo de cancelación. Un intento de enviar pagos artificiales se rechaza. Se libera la mesa y se conserva historia inmutable. Una venta vacía o sin autorización no se convierte en una venta gratuita.

## I. Integración Cash

Aplicar, reemplazar y quitar no genera movimientos. Una venta de total cero no altera el saldo esperado de efectivo. El cierre con pago positivo integra únicamente el efectivo aplicado, por el contrato ya existente, sin duplicar el dinero recibido como ingreso manual. Arqueo y conciliación previos permanecen intactos; un fixture de fondo 100 conserva 100 tras cerrar una venta totalmente descontada.

## J. Idempotencia

Se añaden descriptores Core `sales.discount` (PUT) y `sales.discount-remove` (DELETE). Actor, recurso, UUID v4 estable, método, valor canónico y revisión forman la intención. Mismo contenido/clave recupera el recibo; contenido diferente produce conflicto; otra clave con revisión antigua tampoco duplica la operación. No se crea una segunda tabla o caché financiera.

POS guarda la solicitud congelada en sesión del servidor **antes** de ejecutar, junto a la clave y revisión. Reintento y recarga conservan la intención. No se renueva una clave enviada con estado pendiente o desconocido. La consulta recupera la venta autorizada vigente sin repetir efectos. Vista previa no es una confirmación ni modifica datos.

## K. Concurrencia

Procesos PHP independientes y conexiones SQL reales prueban dos descuentos, agregado, cantidad, retiro, cancelación, Checkout, sustitución, eliminación, retiro de permiso, pérdida de respuesta, deadlock, rollback y misma clave. Se observa bloqueo `LCK_M_*` y SPID distintos, no solo llamadas secuenciales. La prueba adicional de PIN valida la solicitud anterior, cambia realmente de empleado manteniendo su transacción abierta y comprueba el rechazo después de liberar el bloqueo.

El deadlock usa prioridades HIGH/LOW y produce **1205 real**, registrado por el clasificador y reintento existente. Rollback de Audit no deja descriptor, importes ni recibo confirmado; se puede reintentar la misma intención. Errores o eventos secundarios no deshacen un commit confirmado. Los catorce escenarios pasaron en la regresión definitiva: trece carreras HTTP/SQL y una carrera PIN con el servicio real y dos conexiones. [Evidencia de ejecución](qa/1.6b/test-results.json).

## L. Auditoría

`SecurityEvent` incorpora el DTO cerrado `SaleDiscountAudit`: descriptor anterior/posterior, autorizador/UTC, subtotal/descuento/total antes y después. Eventos `sales.discount.applied`, `.replaced`, `.removed`. Los cambios de consumo que recalculan el porcentaje incluyen esa evidencia en su evento normal. `RecordSecurityEvent` conserva simultáneamente la evidencia previa de retiro de línea.

No hay payload libre, PIN, tokens ni excepciones internas en los datos de descuento. Audit participa en la transacción financiera; un fallo revierte la operación. `SaleChanged` sigue siendo secundario tras commit. No se reescriben eventos históricos.

## M. API

`PUT /api/v1/sales/{id}/discount` recibe exclusivamente `type` y `value`. `DELETE` no acepta campos adicionales. Ambos exigen Sanctum vigente, permisos, `Idempotency-Key` UUID v4 e `If-Match`. Se reutilizan `SaleController`, `SalesRequest`, recursos y convenciones JSON de Core. No se envían actor, precio, importe calculado, subtotal, total ni fecha.

El recurso añade `discount_authorization` solo cuando existe: tipo, valor (entero porcentual o string fijo), `authorized_by` ULID y `authorized_at` UTC. Se mantiene el importe `discount` existente. La ausencia del campo preserva la revisión HMAC de ventas sin descuento. 401/403/409/422 y errores internos conservan el saneamiento existente de API.

## N. POS Web

Panel integrado `sales.discounts` dentro de `/pos`: aplicar, sustituir, quitar, cálculo previo y confirmación expresa. Muestra tipo, valor e importes finales y explica el cierre cero. Lo oculta a empleados sin permisos y deshabilita edición financiera de historia cerrada. Botones de 44 px, etiquetas, teclado y mensajes accesibles; sin páginas ni autenticación paralelas.

El ticket se actualiza tras la operación. Si un cobro todavía no enviado tenía un total anterior, se invalida su vista previa para prepararlo otra vez, conservando su clave. Una intención ya enviada no cambia silenciosamente de contenido. Cierre cero muestra folio y ausencia de pagos con botones específicos.

## O. SQL Server

Migración incremental `2026_11_19_000016_enable_authorized_sale_discounts`: cuatro columnas nullable en `sales_sales`: tipo, valor `decimal(19,4)`, autorizador ULID y fecha UTC; FK real a Users con `NO ACTION`. CHECK exige descriptor completo y aritmética coherente. Sin tabla nueva, cascadas, `rowversion` ni migraciones destructivas. Se conserva la protección SQL previa de ventas cerradas.

Si existen descuentos no atribuidos o la columna ya existe, la migración aborta para revisión; no inventa aprobaciones. Su rollback se limita a `_test`. Las pruebas crean y desmontan su esquema exclusivamente en `fresapos_test`, con `TestDatabaseGuard`. No se usan SQLite, Docker, `migrate:fresh` ni `db:wipe`.

Tras pasar la regresión y comparar las 27 tablas originales se creó el respaldo externo al repositorio **`fresapos_pre_1_6b_20261119_164941Z_8c295b9a.bak`**, 10,567,680 bytes, `COPY_ONLY` y `CHECKSUM`. `RESTORE VERIFYONLY` con checksum terminó correctamente; los metadatos de `msdb` confirmaron ambos indicadores. Verificado el 19 de noviembre a las 16:49:41 UTC.

Se aplicó exclusivamente `000016` en **309.99 ms**, lote **14**, registro **16**. Resultado: **27 tablas y 16 migraciones**. Las cuatro columnas son nullable; el valor es `decimal(19,4)` y el autorizador `nchar(26)`. FK `NO ACTION` y CHECK habilitados y confiables. Los valores originales de las 27 tablas siguen idénticos, incluidos los quince registros anteriores de migración; no hay descriptores inválidos ni cierres cero inválidos. El catálogo operativo de motivos sigue vacío. [Evidencia de despliegue](qa/1.6b/deployment-evidence.json). No se conectó a Azure SQL.

## P. Calidad

Resultados focalizados: aritmética **22 pruebas/22 aserciones**, SQL definitivo **13/111**, HTTP **10/222**, Web **5/69**, PIN concurrente **1/13**, misma clave con cruce de segundo **1/19** y reconexión física **1/15**. Las pruebas de componentes Livewire aportan la sesión real creada por login al simulador que desactiva middleware; no sustituyen la validación Auth/SQL. Se conserva además el protocolo HTTP nativo de cambio PIN y la revisión de Chrome con middleware real.

Primera regresión con la implementación: **704 pruebas/8202 aserciones**, 23:49.099, 196 MB, exit 1, dos fallos de expectativas:

1. `CashFinanceConcurrencyTest#8`: SQL Server reutilizó el SPID 68 tras la terminación/reconexión. Se reemplazó la suposición de SPID distinto por la comprobación de GUID `connection_id` distintos y válidos, conservando todas las aserciones de saldo, recibo, pago y auditoría. No cambia código financiero de Cash/Core.
2. Cierre cero porcentual: el intento de cambiar una venta tras cerrar también su caja fue rechazado por el trigger 51035 en vez del 51001. Ambos triggers reales protegen la escritura y no tienen un orden declarado. La prueba reconoce únicamente esos dos rechazos y comprueba adicionalmente que la fila completa permanece idéntica. No se cambia el orden ni se debilitan los triggers.

Después se completó el caso de cobro supervisor/cajero y se corrigió la revalidación de sesión descrita en D. El inventario definitivo es **705 pruebas: 641 anteriores conservadas nominalmente y 64 nuevas**. La regresión definitiva pasó: **705 pruebas/8212 aserciones**, **23:47.364**, **198 MB**, **exit 0**, sin fallos ni pruebas omitidas. La incidencia de barrera observada en la línea base no se reprodujo en ninguna de las dos regresiones posteriores. Pint, Larastan nivel 5 (**374 archivos**), validación estricta de Composer y requisitos de plataforma pasaron tras los últimos cambios.

Comandos desde PowerShell:

```powershell
$env:COMPOSER_PROCESS_TIMEOUT='1800'
.\scripts\local.cmd composer test
.\scripts\local.cmd composer lint
.\scripts\local.cmd composer analyse
.\scripts\local.cmd composer validate --strict
.\scripts\local.cmd composer check-platform-reqs
.\scripts\local.cmd php scripts/inspect-financial-integrity.php
powershell -NoProfile -ExecutionPolicy Bypass -File scripts/validate-openapi.ps1
```

El timeout es temporal al proceso y no cambia `composer.json`. No se borran ni silencian pruebas, ni se amplían barreras para ocultar fallos. La discrepancia inicial de la barrera heredada queda registrada; no se reprodujo en la primera regresión de 704 casos. Durante la preparación se corrigieron también una sintaxis del nuevo caso web y la expectativa de importe del escenario de deadlock: el único descuento que se confirma allí es el segundo, de 30 % (54.0000), porque el primer proceso solo crea el ciclo SQL de bloqueo.

## Q. OpenAPI

OpenAPI 3.1 documenta **64 operaciones**, 25 Sales. Validación formal repetida después de la regresión definitiva: **470 respuestas reales**, muestras de las 64 operaciones, cero errores y advertencias, resultado PASS. Dos operaciones nuevas, entrada cerrada y autorización opcional documentadas. Las respuestas provienen de QA y no publican credenciales.

## R. Revisión visual

Chrome real con servidor local separado en 8084, base `fresapos_test` y archivos/sesiones de QA aislados. Navegador integrado falló al inicializar; se reutiliza el mecanismo CDP existente. Resoluciones 1440×900, 1024×768, 768×1024 y 390×844. Se verifican porcentaje, fijo, reemplazo, eliminación, errores, roles, PIN, doble clic, pérdida real de respuesta 200, recuperación, cierre cero, folio, cobro positivo y teclado.

Se distinguen **85 capturas generales**, **40 de detalle** y **4 adicionales del cajero con una venta seleccionada**. Las de detalle verifican que el panel desplazable muestre realmente los controles. **129 capturas**, sin desbordamiento horizontal ni errores JavaScript observados; se inspeccionaron imágenes de los cuatro tamaños, incluidos controles y cierre cero móviles. Controles financieros con altura mínima de 44 px. La comprobación por teclado alcanzó el botón de cálculo desde el campo de valor.

Evidencia SQL de Chrome: cinco ventas ficticias; tres cierres cero con tres folios y ningún pago asociado; un cierre positivo con un pago; cero movimientos manuales, motivos e intenciones pendientes. La autorización del supervisor por PIN queda atribuida a ese usuario. Se descartó realmente una respuesta HTTP 200 después del commit y se recuperó el descuento tras recargar, sin repetir efectos. El servidor y Chrome de QA se cerraron y sus tablas se desmontaron antes de ejecutar la regresión; el servidor principal 8080 permaneció disponible. [Índice de evidencia](qa/1.6b/README.md).

Después de la regresión se ejecutó una comprobación independiente con esquema 16 y usuario cajero: venta seleccionada de 180.0000, componente `sales.discounts` ausente tanto del DOM como de los snapshots Livewire, comprobado en los cuatro tamaños. SQL confirmó una venta creada por el cajero y cero descuentos, pagos, movimientos o eventos de descuento. Se cerraron sus procesos y se desmontó de nuevo exclusivamente `fresapos_test`. [Evidencia adicional de roles](qa/1.6b/selected-role-sql-evidence.json).

## S. Integridad

Se preservan `.env`, `.env.testing`, conexión SQL, sesiones, almacenamiento, `composer.json`, lock, PHPUnit, Larastan y configuración Users mediante comparación SHA-256 de diez archivos. La comparación privada de las columnas y filas originales de las 27 tablas excluye únicamente la futura fila nueva de migración. No se publican digests de filas ni credenciales.

Comprobación final después de la migración y las solicitudes HTTP: **27 tablas originales sin cambios de valores**, **diez archivos protegidos idénticos**, **16 migraciones**, **59 columnas ULID revisadas** y **21 controles de integridad sin violaciones**. Se comprobaron además el descriptor de descuento, sus restricciones, la FK y la ausencia de cierres cero inválidos. [Integridad financiera de solo lectura](qa/1.6b/operative-invariants-final.json).

**Trece comprobaciones HTTP** correctas en `http://127.0.0.1:8080`: inicio, `/up`, `/api/v1/ping`, login, accesos protegidos de Catalog/Sales/motivos/POS/Cash y recurso JavaScript Livewire. [Resultados de arranque](qa/1.6b/health-final.json). La base `fresapos_test` quedó con **cero tablas** al cerrar. [Desmontaje](qa/1.6b/test-database-final.json). No se realizaron ventas ni operaciones de prueba en `fresapos`.

## T. Pendientes

Azure SQL y cPanel no verificados: `pdo_sqlsrv`, ODBC, TLS, autenticación SQL, firewall y compatibilidad del hosting/ionCube requieren entorno autorizado. Scheduler del sistema conserva los pendientes anteriores. Fuera de alcance: promociones, cupones, múltiples descuentos, descuentos por línea, correcciones posteriores al cobro, reembolsos, devoluciones, reaperturas, división de cuentas, tickets, reportes, inventario y Android.

Sin commits, pushes ni despliegues externos. La etapa termina en 1.6b; no se avanza automáticamente a 1.6c.
