# Entrega 1.6a — Motivos autorizados y operaciones controladas de Sales

18 de noviembre de 2026. **ETAPA 1.6a: ACEPTADA LOCALMENTE**. Catálogo operativo vacío por ausencia de motivos aprobados; sin despliegues externos.

## A. Diagnóstico

Repositorio existente en `main`, HEAD inicial `c3fd2db`, árbol limpio. PHP 8.3.36, Laravel 13.42.0 y SQL Server Express, instancia `.\AUDITORIA`, con autenticación Windows. Se inspeccionaron informes previos, módulos, migraciones, servicios, rutas, permisos, PIN, POS, Cash, Audit, Core, pruebas y OpenAPI.

Línea base real: **593 pruebas / 6832 aserciones**, 14:50.399, 166 MB, exit 0. Pint y Larastan nivel 5 correctos. Se conservan las 593 pruebas; los fixtures históricos que deliberadamente no instalan el catálogo usan adaptadores explícitos exclusivos de `testing`.

Se reutilizan `SalesOperations`, `CoordinateSalesWrite`, `CriticalSalesWrites`, `DurableOperations`, `OperationIntent`, `TransactionManager`, las aprobaciones de retiro/cancelación, el contrato de Cash, `IdentityAccess`, `SalesPolicy`, `PosAccess`, `PosIntentRegistry`, sesiones Auth, PIN, eventos secundarios y auditoría central. No se añaden paquetes ni módulos. El catálogo pertenece a Sales.

## B. Catálogo

`sales_operation_reasons`: `id` ULID nativo, `code` estable y globalmente único, `name`, `description` opcional, `operation`, `is_active`, `display_order`, `created_at` y `updated_at` UTC. Nombre hasta 150 unidades UTF-16, descripción hasta 500; texto plano normalizado, sin HTML ni controles. Código de 1–64 caracteres: mayúsculas ASCII, números, `_`, `:`, `-`; comienza por letra o número. Orden entero entre 0 y 2147483647.

Tipos cerrados: `sale_item_removal` y `sale_cancellation`. Un motivo tiene un único tipo. Nombre único por tipo con colación `Latin1_General_100_CI_AI_SC`; no se duplican variantes equivalentes por acento/mayúscula. Orden de consulta: orden, nombre, ULID. Paginación 10/25/50/100; el selector POS muestra 25 por página.

**No se encontraron motivos operativos aprobados. No hay seeder operativo, razones predeterminadas ni texto libre alternativo.** El administrador debe configurar motivos reales mediante la pantalla protegida. Los códigos `QA_*` y los nombres de prueba existen exclusivamente en `fresapos_test` y se eliminan al desmontar los fixtures.

## C. Administración

`/admin/sales/reasons` integra Blade/Livewire en la navegación existente. Permite crear, editar nombre/descripción/orden y activar/desactivar con confirmación. No permite eliminar, cambiar código, cambiar tipo ni atribuir actor desde el cliente. Crear deja el motivo activo salvo elección explícita en la API. Las modificaciones exigen la revisión vigente mediante `If-Match`; una revisión obsoleta falla con 409.

Se usan los permisos reales `sales.view` y `settings.update`. No se concedieron permisos nuevos ni se modificaron los roles del entorno operativo. La pantalla conserva su intención congelada antes de enviar, bloquea la renovación cuando se desconoce el resultado y consulta el estado de Core.

## D. Retiro

Retira una línea completa de una venta **abierta**, con `sales.view`, `sales.open.update` y `sales.special.authorize`. Se exige código de motivo activo para retiro, caja original abierta, línea perteneciente a la venta, revisión vigente y UUID v4. Cantidades inválidas no se convierten en una eliminación silenciosa.

La línea conserva producto/categoría, precio aplicado, cantidad, nota, importe original y posición. Se guardan actor, fecha UTC, código, FK al motivo y copia histórica de sus datos. El total y subtotal se recalculan sobre líneas activas con aritmética decimal existente. El retiro de la última línea produce cero; no elimina físicamente productos ni ventas, no cambia inventario y no registra dinero.

## E. Cancelación

Cancela ventas abiertas o pausadas sin pagos confirmados con `sales.view` y `sales.cancel`. Valida el motivo de cancelación dentro del servicio de aplicación y de la transacción, también para consumidores internos. Conserva líneas, importes, caja original, actor y UTC; registra FK e instantánea del motivo. Libera la mesa mediante el ciclo de vida existente.

Ventas pagadas, cerradas o canceladas se rechazan. No emite folio, no toca secuencias, no reembolsa, no modifica pagos, no genera movimientos Cash y no reabre ventas. La actualización de estado es única: no se ejecuta una segunda escritura después de quedar cancelada. SQL protege también las líneas de ventas canceladas.

## F. Integración Cash

Las operaciones usan `ActiveCashSession` y la sesión original de la venta. No fabrican sesiones ni alteran fondos, movimientos, pagos o arqueos. Una sesión cerrada impide nuevas mutaciones. Un recibo Core ya confirmado puede recuperarse después del cierre sin volver a ejecutar la operación. El saldo esperado de los fixtures permanece en 100.0000 después de retirar/cancelar; los pagos existentes siguen sumándose mediante el contrato financiero previo.

## G. Idempotencia

Se extiende `DurableSalesWrites` con `sales.remove` y `sales.cancel`; recurso, actor, UUID v4, método, código y revisión original participan en el hash canónico de Core. Administración usa `sales.reason.create/update/status`. No existe un segundo registro ni caché financiera paralela.

Misma clave y contenido: recibo persistido, sin segundo efecto ni auditoría. Misma clave y contenido distinto: conflicto. Otra clave sobre un estado anterior: conflicto de revisión/estado. Se comprueban usuario y permisos **antes del replay**. Desactivar o renombrar el motivo no impide recuperar una operación ya confirmada; las nuevas operaciones sí vuelven a validar el catálogo activo. Los recibos vencidos mantienen su referencia conforme al comportamiento previo de Core.

POS conserva clave, objetivo y solicitud congelada en sesión del servidor antes de ejecutar. Una respuesta perdida se consulta con la misma clave; no se crea una nueva intención automáticamente. La recuperación vuelve a leer la venta autorizada vigente. No se usa `rowversion`.

Compatibilidad histórica: los campos de evidencia nuevos se omiten del cálculo de revisión cuando son nulos. Las revisiones HMAC y los recibos de ventas anteriores no cambian por agregar propiedades opcionales. Los Resources añaden la instantánea solamente cuando existe.

## H. Concurrencia

Orden conservador existente: identidad global, caja/sesión, venta y motivo. `UPDLOCK/HOLDLOCK` protege los motivos hasta el commit; la administración también participa en el bloqueo de identidad. Un cambio de permisos o desactivación se serializa con el uso del motivo.

`SaleReasonsConcurrencyTest` ejecuta **12 escenarios SQL/HTTP en procesos independientes**: retiro/retiro, retiro/cantidad, retiro/checkout, cancelación/checkout, dos cancelaciones con distintas claves, misma clave, cancelación/agregado, desactivación concurrente, retirada de permisos, respuesta perdida después del commit, deadlock y rollback. Se comprueban SPID distintos y bloqueos `LCK_M_*` cuando corresponde; no se sustituyen por arrays o SQLite.

El deadlock descubierto en una transacción anidada llega como `Illuminate\Database\DeadlockException`, cuyo `errorInfo` está vacío. Core ahora examina su causa SQL reconocida y mantiene los cinco intentos selectivos existentes. No reintenta por buscar texto «deadlock» ni clasifica un error de negocio como transitorio. El caso SQL real verifica 1205 y un único efecto; una prueba aislada comprueba que 547 y errores genéricos siguen sin ser recuperables.

## I. Seguridad

| Rol actual | Administrar motivos | Retirar | Cancelar |
| --- | --- | --- | --- |
| admin | Sí | Sí | Sí |
| supervisor | No | Sí | Sí |
| cashier | No | No | No |
| waiter | No | No | No |

La tabla representa la matriz inicial; las decisiones se toman con permisos vigentes del usuario activo. No cambia la matriz operativa. Sesión Web, API bearer y PIN conservan sus reglas previas. Auth conserva la restricción existente que excluye del acceso por PIN a identidades con permisos Users/Roles (incluido el administrador inicial). `PosAccess` exige permisos vigentes y los componentes bloquean el contexto del empleado. Después de cambiar por PIN, el componente anterior no puede actuar con el empleado anterior. No se aceptan `actor_id`, fechas, importes calculados ni atributos internos en el payload.

Los nuevos componentes se incluyen en el saneamiento de errores sensibles de Auth. La API utiliza el manejo uniforme de `/api/v1` y no devuelve SQL, rutas del servidor ni diagnósticos internos con debug activo o inactivo. Las pruebas usan login y sesiones reales, además de tests de componentes; no se usa `actingAs` para simular el cambio por PIN.

## J. Auditoría

`SecurityEvent` se amplía con DTO cerrados `SaleOperationAudit` y `ReasonChangeAudit`; Audit los persiste en `audit_security_events.change_data`. No se crea otra tabla de auditoría. Retiro: actor, venta, línea/producto/nombre históricos, cantidad, precio, importe, motivo completo, estado anterior/final y UTC. Cancelación: actor, venta, motivo, estados y UTC. Administración: alta, modificación, activación/desactivación con antes/después saneados.

La auditoría es obligatoria dentro de la transacción: si falla, se revierten venta, evidencia del motivo y recibo Core. Un replay no duplica el evento. Los eventos secundarios posteriores al commit conservan su tratamiento existente y no revierten operaciones confirmadas. Cambiar el nombre o desactivar el motivo no reescribe evidencia pasada.

## K. API

| Método y ruta bajo `/api/v1` | Uso |
| --- | --- |
| GET `/sales/reasons?operation=sale_item_removal` | Motivos activos autorizados para la operación |
| GET `/sales/reasons?administration=true` | Listado administrativo con filtros y paginación |
| GET `/sales/reasons/{id}` | Detalle administrativo |
| POST `/sales/reasons` | Crear, UUID v4 |
| PUT `/sales/reasons/{id}` | Nombre/descripción/orden, UUID v4 e If-Match |
| PATCH `/sales/reasons/{id}/status` | Activar/desactivar, UUID v4 e If-Match |
| DELETE `/sales/{id}/items/{itemId}` | Retiro existente habilitado con `reason_code` |
| POST `/sales/{id}/cancel` | Cancelación existente habilitada con `reason_code` |

No hay endpoint nuevo alternativo para retirar o cancelar. Ambos exigen `Idempotency-Key` UUID v4 e `If-Match` entre comillas sobre la revisión de la venta. Los Form Requests rechazan campos extra y los Resources limitan los atributos expuestos. Respuestas uniformes: 200/201 éxito, 400 UUID ausente/inválido, 401 sin sesión, 403 sin permiso, 404 recurso ajeno/inexistente, 409 revisión/estado/motivo incompatible o conflicto de intención, 422 validación. Core conserva `OPERATION_IN_PROGRESS` y `IDEMPOTENCY_KEY_CONFLICT`. Un código sintácticamente válido pero no autorizado se rechaza con 409; no se admite texto libre.

## L. POS Web

Se integra `sales.operations` en el ticket existente; no se reconstruye POS. Las acciones aparecen según permiso y estado. La confirmación muestra producto/cantidad/importe histórico o total de la venta y exige seleccionar motivo activo compatible. Sin motivos, explica el bloqueo y deshabilita confirmar. Conserva la misma intención en doble clic, reintento y recarga, muestra estado, permite consulta y actualiza el ticket mediante el evento del componente. Una cancelación elimina la venta del listado activo y libera la mesa; el resultado histórico continúa visible mientras está seleccionada.

Administración y selectores son responsivos; controles nuevos con mínimo 44 px, foco y etiquetas. Los botones futuros quedan restringidos a descuentos/tickets. No se habilitan correcciones, devoluciones, inventario, cuentas divididas ni impresión.

## M. SQL Server

Migración incremental única `2026_11_18_000015_create_sale_operation_reasons.php`. ULID nativo de Laravel genera `nchar(26)` en SQL Server (52 bytes). Agrega dos FKs reales NO ACTION y cuatro columnas nullable de evidencia en tablas existentes; las filas anteriores conservan NULL y sus valores originales. No hay backfill ni migraciones de negocio adicionales.

Restricciones de tipo, orden, texto/código, unicidad e instantáneas JSON; tres triggers protegen identidad del motivo/borrado físico, líneas retiradas o de ventas canceladas y ventas canceladas. No se eliminan ni desactivan las protecciones financieras anteriores. `down()` aborta fuera de bases terminadas en `_test`; no se usa sobre `fresapos`.

Despliegue local ejecutado después de la regresión: backup `COPY_ONLY/CHECKSUM` y `RESTORE VERIFYONLY` correctos; migración 000015 en lote 13. Resultado: 27 tablas y 15 migraciones, catálogo vacío, tres triggers nuevos habilitados y dos FKs confiables. Las 26 tablas proyectadas sobre sus columnas originales y las 14 migraciones anteriores conservan sus valores. Datos de administrador y diez archivos protegidos idénticos. No se utilizarán `migrate:fresh`, `db:wipe`, credenciales Azure ni conexiones alternativas.

## N. Calidad

Regresión definitiva: **641 pruebas / 7517 aserciones**, 15:23.190, 184.00 MB, exit 0. Conserva las 593 pruebas anteriores y agrega 48. Comando: `.\scripts\local.cmd composer test -- --stop-on-failure`. Pint, Larastan nivel 5 (366 archivos), Composer estricto y plataforma pasan con exit 0. Comandos: `.\scripts\local.cmd composer lint`, `composer analyse`, `composer validate --strict`, `composer check-platform-reqs`, siempre mediante el wrapper local. Evidencia en [QA](qa/1.6a/test-results.json). La primera regresión completa terminó con 641/7483 y tres fallos de expectativas/fixtures; se corrigieron, se validaron focalizadamente y se repitió toda la suite. No quedan fallos conocidos. Salud en 8080: nueve comprobaciones correctas, incluidas `/up`, ping, login, Livewire y protección de POS/Cash. Las ejecuciones intermedias detectaron ajustes de fixtures (login repetido, códigos HTTP y rutas de Resource), una expectativa explícita de controladores y el deadlock anidado descrito en H; no se omitieron pruebas ni se añadieron exclusiones a Larastan.

## O. OpenAPI

Se documentan las cinco operaciones nuevas y se actualizan los dos endpoints sensibles existentes: 62 operaciones totales, 23 de Sales, 15 de Catalog y 13 de Cash. Esquemas cerrados para motivo, copia histórica, entrada y envoltorios. Validación formal 3.1 y contraste con rutas reales; **434 respuestas reales de las 62 operaciones**, cero errores y advertencias. Se ejecutó `powershell.exe -NoProfile -ExecutionPolicy Bypass -File scripts/validate-openapi.ps1 -Responses .tools/reasons-all-responses.json -Report .tools/reasons-openapi-responses-report.json`. Las respuestas nuevas se combinan con la evidencia previa de 388 respuestas para conservar cobertura contractual.

## P. Revisión visual

Chrome 157.0.8103.79 real, exclusivamente sobre `fresapos_test`: **85 capturas**, 1440×900, 1024×768, 768×1024 y 390×844. Catálogo vacío, formularios, listado, desactivación/activación, venta abierta, motivo inactivo, retiro, cancelación, respuesta perdida recuperada, mesa liberada, venta pagada y acceso por cashier/PIN. Sin desbordamientos horizontales ni errores JavaScript de página. Teclado y doble clic comprobados. Los controles nuevos cumplen 44 px; los controles históricos de cuenta/login conservan su tamaño previo.

Se corrigió la sincronización del panel hijo después de agregar productos: el ticket emite `sale-ticket-refreshed` y el panel relee la venta autorizada, sin alterar una intención preparada. El guion de teclado necesitó esperar la transición responsiva de Alpine antes de comprobar Tab; la aplicación no se modificó para ocultar esa condición. Se descartó una respuesta Livewire 200 después del commit y la recarga recuperó el recibo. SQL confirmó un retiro, dos cancelaciones, un pago, cero movimientos manuales y cero intenciones pendientes. Los fixtures se desmontaron: cero tablas antes de la regresión definitiva.

Evidencia sin cookies, tokens, contraseñas ni datos operativos en [QA 1.6a](qa/1.6a/README.md). La herramienta Computer Use no está expuesta en esta sesión; se utilizó Chrome local mediante CDP, como en la entrega anterior. El 403 conserva el texto histórico del framework en inglés; no es una traducción nueva de esta entrega.

## Q. Integridad

Se capturaron huellas privadas de las 26 tablas iniciales y de diez archivos protegidos. La comparación final proyectó las columnas originales y las 14 filas de migración originales para no confundir columnas nullable nuevas con alteración histórica. Administrador, credenciales, configuración, permisos, caja, pagos, arqueos y recibos anteriores se conservaron. Las 21 invariantes financieras pasan; 58 columnas ULID verificadas. `fresapos_test` terminó sin tablas. No se publican hashes de filas ni secretos. Las pruebas y la revisión visual usan la base independiente con salvaguarda de nombre `_test`.

## R. Pendientes

Motivos reales aprobados: no recibidos; el catálogo operativo permanecerá vacío y las dos operaciones bloqueadas hasta configuración administrativa. No se requiere inventar ni aprobar una semilla ficticia.

Quedan fuera de esta entrega: descuentos, correcciones financieras, devolución/reembolso, reapertura, tickets, reportes PDF/Excel, inventario, recetas, división de cuentas, Android y dispositivos. Azure SQL y cPanel no se han conectado ni desplegado: pendientes externos de `pdo_sqlsrv/sqlsrv`, ODBC, TLS/certificados, firewall, permisos SQL/DDL/triggers, almacenamiento, ionCube y Scheduler. La ejecución local no demuestra esos entornos. Sin commits, pushes ni avance automático a 1.6b.
