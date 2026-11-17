# Entrega 1.5d.2b — Métodos de pago y movimientos de efectivo

Fecha: 17 de noviembre de 2026. **ETAPA 1.5d.2b: ACEPTADA LOCALMENTE**. No se habilitan cobros definitivos.

## A. Estado inicial

Se inspeccionaron rama main, Git, los informes de Cash 1.5d.1 e idempotencia 1.5d.2a, contratos/informes de Sales, Core, Users/Auth, Audit, decimales, migraciones, API/OpenAPI y pruebas SQL concurrentes. HEAD `42fea6d`. Los cambios aceptados de 1.5d.2a estaban presentes sin commit y se conservaron; no se reconstruye ningún módulo.

PHP 8.3.36, Laravel 13.42.0, Sanctum 4.4.0, Livewire 4.4.8, SQL Express AUDITORIA y autenticación Windows existentes. Línea base ejecutada antes de editar código: **455 pruebas / 4653 aserciones, 9:20.380, 128 MB**, exit 0. Pint y Larastan nivel 5, 296 archivos, correctos. Logs privados `.tools/finance-1.5d2b-baseline-*`.

La base operativa tenía 21 tablas, nueve migraciones, un administrador activo, una sesión Cash, **una venta real y tres recibos de idempotencia previos**. Se capturaron huellas de todas sus filas y de diez archivos protegidos; no se presupone que Sales esté vacío. No existían métodos persistentes, movimientos o pagos. Se reutilizaron permisos existentes: cash.view, cash.movements.create y cash.administrative.execute, sin modificar roles ni asignaciones productivas.

## B. Métodos de pago

`cash_payment_methods` contiene ULID, código estable único, nombre visible, estado activo, orden y timestamps. Códigos cerrados: cash/card/transfer/other. La persistencia se justifica por desactivación y conservación de referencias históricas reales desde pagos; un enum aislado no conserva una decisión administrativa.

`PaymentMethods`, DTO inmutable y `ManagePaymentMethods` ofrecen consulta de activos, detalle activo y desactivación/reactivación administrativa interna. Consultas exigen cash.view; cambios de estado requieren además cash.administrative.execute. No existe CRUD arbitrario desde POS ni endpoint para crear métodos. Listar other no autoriza a usarlo en una cotización.

`cash:payment-methods:provision` crea solo los cuatro faltantes, coordinado por la transacción/bloqueo de identidad existente. No renombra, reactiva ni duplica registros al repetir. Los modelos impiden cambiar ID/código o borrar; las FK protegen referencias SQL. Desactivar bloquea nuevas preparaciones, sin alterar pagos previos, sesiones Cash ni movimientos manuales. La reactivación es explícita y autorizada.

## C. Persistencia de pagos preparados

`sales_payments`, dentro de Sales, tiene ULID, venta, sesión Cash, método/código, importe aplicado, efectivo recibido/cambio opcionales, referencia corta, actor, estado, fechas y confirmación opcional. Seis FK nuevas en conjunto con movimientos, todas sin cascada; la FK compuesta método/ID impide un código incoherente con su identidad.

**La restricción SQL solo admite status=prepared y confirmed_at NULL.** No hay servicio de confirmación, endpoint de cobro, botón financiero, folio o cierre. La persistencia se prueba creando filas únicamente en fresapos_test con casos aislados. Ninguna operación Web/API productiva de esta etapa crea filas sales_payments.

`PreparePayments::quote` ofrece preparación interna autorizada, bajo transacción/identidad actuales: exige sales.view, sales.close y cash.view, venta open y sesión Cash original vigente. Obtiene métodos activos mediante contrato público. Other exige cash.administrative.execute del actor real. Devuelve PaymentPlan/PreparedPayment inmutables y no persiste ni confirma nada. No recibe actor desde un body HTTP y no se publica una ruta anticipada.

Las FK y CHECK protegen coherencia por fila; la suma entre pagos, la correspondencia de la sesión original y la confirmación del conjunto se deberán coordinar en el futuro servicio financiero. No se afirma que una CHECK pueda validar agregados entre filas. El cálculo/quote actual valida el conjunto y contexto; la barrera SQL impide convertir borradores en ingresos por escritura accidental.

## D. Entradas y salidas

`CashMovements` y `ManageCashMovements` registran cash_in/cash_out, ULID de sesión explícita, importe positivo, concepto normalizado y actor autenticado. La sesión debe estar abierta, pertenecer a la caja física activa y coincidir con la intención original. No se infiere una sesión distinta al reintentar.

El concepto requiere contenido alfanumérico, NFC, espacios normalizados, texto plano y hasta 200 unidades UTF-16. No existe todavía un catálogo funcional de motivos autorizados para reutilizar; no se sustituye el contrato pendiente de cancelaciones Sales por este concepto manual.

Se exige cash.view + cash.movements.create, identidad activa, contraseña cambiada y acceso vigente en el transporte. El mesero no obtiene capacidades financieras. No se asignan permisos nuevos ni se implementa edición, borrado, reembolso o corrección de movimientos. Los modelos rechazan actualización/borrado; no hay rutas PUT/PATCH/DELETE. Las compensaciones futuras requieren reglas propias.

## E. Decimales, efectivo y pagos mixtos

Se extrajo la aritmética existente de Sales a `Core/Contracts/DecimalAmount`; Amount de Sales conserva su contrato mediante delegación. Así Cash y Sales comparten suma/resta/multiplicación exactas sin depender Cash de Sales ni crear un ciclo. No se incorporan extensiones, librerías monetarias ni floats. Los dígitos se calculan como enteros pequeños/texto; se comprueba el límite decimal(19,4), incluso cuando el valor escalado excede PHP_INT_MAX.

Entrada: texto decimal, máximo 15 enteros y cuatro decimales; rechaza números JSON, exponentes, negativos, separador coma, ceros iniciales arbitrarios y precisión excedida. Salida: cuatro decimales. Se conserva la convención actual de Sales/moneda, sin introducir divisas ni redondeos a dos decimales. No se redondea silenciosamente un importe inválido.

`PaymentAllocation` calcula total aplicado, faltante, efectivo neto aplicado y cambio. 180 aplicado/200 recibido produce cambio 20 y efectivo reconocido 180. En mezcla 150 efectivo+200 tarjeta=350, el efectivo neto es 150; tarjetas/transferencias no alteran efectivo. Recibido/cambio solo corresponden a cash. Un importe aplicado es positivo, el recibido no puede ser inferior, la suma nunca supera el total y el modo completo exige igualdad exacta. La preparación parcial explícita devuelve faltante sin confirmación.

Transfer/other requieren referencia de comprobante. Las referencias son cortas, con caracteres restringidos; rechazan secuencias largas de dígitos, también separadas, para impedir PAN/datos bancarios evidentes. No hay campos PAN/CVV, proveedor bancario o integración electrónica. Se admiten hasta veinte aplicaciones por cotización como límite defensivo interno; no representa un límite de métodos configurables desde POS.

## F. Saldo esperado

Saldo = fondo inicial + entradas manuales − salidas manuales + efectivo de ventas realmente confirmado. En esta etapa el último componente es **0.0000**: sales_payments no admite confirmados. No se cuentan ventas abiertas, tarjetas, transferencias, borradores ni el efectivo recibido completo antes de entregar cambio.

El agregado utiliza SUM/CASE sobre decimal de SQL, con retorno decimal(38,4), y se valida contra la representación decimal(19,4). También se limita el acumulado de cada dirección para que el resumen permanezca representable. Sobrepasar rango o saldo rechaza la operación sin cambios. Se normaliza la representación .0000 que puede devolver sqlsrv a 0.0000; no se convierte a float.

Antes de una salida se calcula el saldo bajo el mismo bloqueo de sesión/transacción que inserta el movimiento. No hay saldo editable del navegador, actualización materializada redundante o doble cómputo del fondo.

## G. Idempotencia reusable

Se utiliza exclusivamente IdempotentOperations de Core y su tabla vigente. No se crea otro motor/tabla de recibos en Cash y la apertura de caja conserva su mecanismo de 1.5d.1.

UUID v4, contexto del actor y hash de operación/método/sesión/tipo/importe/concepto canónicos. 12.5 y 12.5000, Unicode equivalente y espacios normalizados representan la misma intención cuando el concepto resultante coincide. Cambio significativo con la misma clave: IDEMPOTENCY_KEY_CONFLICT; lock activo: OPERATION_IN_PROGRESS (409).

Reautoriza antes del lookup/replay y bajo lock de identidad. Movimiento, Audit y resultado terminal se comprometen juntos. Rechazo definitivo deja solo recibo failed saneado después de rollback; fallo interno revierte negocio/recibo. Confirmado se reproduce incluso si su sesión posteriormente se cierra, sin crear otra operación. TTL, compactación horaria y referencias históricas de 1.5d.2a permanecen intactos; la expiración no habilita duplicación.

La consulta genérica autenticada de Core permite reconciliar la clave. No se exponen cuerpo almacenado, hash o resultados ajenos. Máximo cinco intentos selectivos y backoff de Core; no se reintentan validación o permisos.

## H. Concurrencia y recuperación

Orden: autorización sin lock → appLock contexto/UUID → transacción Core → coordinación de identidad → caja → sesión → saldo/movimiento → Audit/recibo → commit. Los locks UPDLOCK/HOLDLOCK existentes permanecen hasta el commit; dos UUID distintos no consumen simultáneamente el mismo saldo. La coordinación de identidad es conservadora y global; no se afirma rendimiento de carga medido.

Nueve carreras con procesos PHP, kernels HTTP y SPID SQL independientes: dos entradas, dos salidas, salidas que juntas exceden saldo, transición de sesión, misma clave, clave con datos distintos, rollback, pérdida de respuesta y terminación SQL después del commit. Las carreras de saldo comprueban espera LCK_M_* y bloqueador real; no basta una secuencia de llamadas.

La transición closed ocurre solo en fixture SQL, sin caso de cierre productivo. Dos salidas 70/70 sobre 100 producen un éxito y un conflicto, saldo 30. En la última carrera otra conexión protegida a fresapos_test ejecuta KILL del SPID de su propio worker después de commit real: la siguiente consulta genera error del driver y Core se reconecta/consulta recibo antes de cualquier nueva ejecución. Resultado en la suite final: SPID 54→81, 201 inicial y replay, un movimiento, un evento, saldo 110. No se matan sesiones operativas ni se introduce ese código en producción; es una prueba de terminación de sesión SQL, no una afirmación de corte físico de red.

## I. Seguridad

Permisos efectivos actuales, identidad del actor desde sesión/bearer, middleware persistente y comparación del contexto bloqueado. Un snapshot del empleado anterior no puede operar tras cambio por PIN; una intención del nuevo empleado usa otro contexto. Se prueban token/acceso revocado, inactividad, retirada de permisos y ausencia de bypass administrador.

cash.view autoriza lectura operacional de todos los movimientos de la **sesión actual compartida**, como la consulta Cash existente; no inventa propiedad exclusiva por cajero. Otras sesiones y filtro de otro usuario requieren cash.administrative.execute. Intentos de detalle histórico no autorizado devuelven 404. Un UUID no concede permisos. Filtros y campos desconocidos se rechazan; actor, saldo, cambio, estado y auditoría no vienen del cliente.

Los errores Core se mantienen saneados en APP_DEBUG true/false. SensitiveErrors incorpora el nombre del componente Cash nuevo, conservando protección Web/Livewire. No se almacenan credenciales ni datos bancarios en metadata de intención o navegador. Configuración de autenticación y seguridad existente preservada.

## J. Auditoría centralizada

Se amplía la lista cerrada SecurityEvent con cash.movement.recorded y cash.payment_method.updated. CashMovementAudit es un DTO validado de sesión/tipo/importe; Audit lo serializa en el change_data JSON ya existente junto a actor, record_id, fecha UTC y resultado del registro central. No se crea tabla/listener paralelo ni se almacena concepto libre, clave, hash o datos de tarjeta.

Un movimiento confirmado tiene un evento, replay no agrega otro y un fallo de auditoría revierte todo. Los conflictos de clave conservan el diagnóstico central de Core. Cambios administrativos de estado se auditan; no-op/provision repetido no produce eventos financieros engañosos.

## K. API

Cinco operaciones nuevas, con Sanctum/auth.access.api y no-store, bajo /api/v1/cash:

| Método y ruta | Reglas |
| --- | --- |
| GET payment-methods | Métodos activos, cash.view; máximo cuatro |
| GET payment-methods/{id} | Detalle activo, ULID; inactivo/no existente 404 |
| GET movements | Sesión/tipo/fecha UTC/usuario, page y per_page 10/25/50/100; historial y saldo coherentes dentro de una transacción |
| GET movements/{id} | Detalle sujeto al alcance de sesión/permisos |
| POST movements | Sesión explícita, cash_in/cash_out, amount como texto, concept y cabecera UUID4; cash.movements.create + cash.view |

Resources solo presentan DTOs y el sobre Core existente, X-Request-Id y meta.request_id. La primera escritura y su replay devuelven 201 para el mismo recurso confirmado. Errores 400 clave inválida, 401/403, 404, 409 saldo/sesión/clave/progreso, 422 datos, 429 y 500/503 saneados. No hay endpoint para cobrar ni modificar métodos o historial.

OpenAPI final: **51 operaciones, ocho Cash; 255 respuestas reales de 41 operaciones contrastadas contra el contrato; cero errores y cero advertencias**. La comprobación formal incluye las 51 operaciones; no se inventan muestras para las diez sin respuesta exportada. Informe: [QA](qa/1.5d.2b/finance-1.5d2b-openapi-final.json).

## L. Interfaz Web

Se conserva CashDesk y se agrega CashMovementsPanel dentro de /cash; no se reconstruye la pantalla ni se altera POS. Al cambiar la sesión mostrada, la clave de montaje del panel actualiza su contexto visual sin cambiar silenciosamente la intención financiera.

Muestra fondo existente, entradas/salidas, saldo teórico, historial paginado con fecha UTC/referencia/tipo/importe/concepto/actor, errores y carga. Formulario accesible con labels, controles de al menos 44 px y tablas adaptadas a móvil. Sin sesión o permiso no permite confirmar. No incluye componentes anticipados de cobro porque aún no existe confirmación financiera.

UUID4 bloqueado en snapshot y guardado como metadata de sesión del servidor **antes** del negocio. La misma intención se conserva tras respuesta perdida y recarga. Tras resultado terminal el usuario prepara explícitamente otro movimiento; no se renueva la clave automáticamente ni se borra una intención pending. El servidor conserva sesión original bloqueada, reautoriza cada acción y admite una escritura por paquete Livewire; el disabled es solamente UX.

Se prueban dos llamadas en un paquete, replay del snapshot firmado anterior, recarga, preparación explícita, validación, saldo insuficiente, pending con un lock SQL real, permisos y cambio por PIN. La interfaz no calcula saldo o cambio en JavaScript.

## M. SQL Server

Migraciones incrementales: Cash 000010 (métodos/movimientos) y Sales 000011 (pagos preparados). ULID nchar(26), importes decimal(19,4), FK NO ACTION, índices por sesión/fecha/tipo/actor y unicidad de códigos; CHECK de valores, coherencia de efectivo/cambio y barrera de confirmación.

No se recrea ni actualiza una tabla anterior. Movimiento inmutable no necesita updated_at: tiene occurred_at y created_at UTC. Los down se restringen a bases cuyo nombre termina en _test; todas las suites verifican TestDatabaseGuard y eliminan la conexión operativa de su configuración.

Se compararon las 21 tablas antes de desplegar y seguían intactas. Respaldo real `fresapos_pre_1_5d2b_20261117_185607Z_4359a946.bak`, fuera del repositorio, **8 994 816 bytes**, COPY_ONLY/CHECKSUM y RESTORE VERIFYONLY satisfactorios, verificado a las 18:56:08 UTC. La ubicación privada se conserva en .tools, sin publicar rutas del servidor.

Después se ejecutaron las dos migraciones incrementales con `artisan migrate --path=... --no-interaction`: Cash en 94.13 ms, batch 9, y Sales en 66.75 ms, batch 10. `artisan cash:payment-methods:provision` creó los cuatro métodos. `artisan migrate:status` confirmó las once migraciones aplicadas. La base pasó de 21 a 24 tablas; movimientos y pagos permanecen vacíos. Sin cambios en .env, migrate:fresh, db:wipe o rollback operativo.

## N. Calidad

Suite completa final: **504 pruebas / 5216 aserciones, 11:22.663, 140 MB, exit 0**. Frente a la línea base: +49 pruebas, +563 aserciones, +2:02.283 y +12 MB; las 455 anteriores se conservan. Incluye cálculos y dominio SQL, preparación, API, Livewire y nueve carreras independientes. Feature intermedio: 204 casos / 831 aserciones antes del último caso de referencia separada; no se confunde ese resultado con la suite final.

| Comando PowerShell desde la raíz | Resultado real |
| --- | --- |
| `.\scripts\local.cmd composer test` | 504/5216, exit 0; COMPOSER_PROCESS_TIMEOUT=1500 solo en el proceso, restaurado al terminar |
| `.\scripts\local.cmd composer lint` | Pint correcto, también repetido al cierre |
| `.\scripts\local.cmd composer analyse` | Larastan nivel 5, 322 archivos, cero errores |
| `.\scripts\local.cmd composer validate --strict` | composer.json válido, exit 0 |
| `.\scripts\local.cmd composer check-platform-reqs` | Requisitos satisfechos, exit 0 |
| `powershell.exe -NoProfile -ExecutionPolicy Bypass -File scripts/validate-openapi.ps1 -Responses .tools/finance-1.5d2b-all-responses.json -Report .tools/finance-1.5d2b-openapi-final.json` | 51 operaciones, 255 respuestas, cero errores/advertencias |
| `git diff --check` | Sin errores de espacios; avisos informativos de normalización CRLF/LF |

Logs completos locales `.tools/finance-1.5d2b-tests-final.log`, `final-analyse.log`, `final-composer.log`, `platform.log` y `closing-lint.log`, con el mismo prefijo finance-1.5d2b. No se añadieron dependencias ni se alteraron composer.json/lock o configuraciones de análisis.

Fallos intermedios conservados: un assertSame comparaba identidad de DTOs nuevos en vez de sus valores al repetir provisión; se corrigió a igualdad de valor, conservando IDs/códigos comprobados. El primer análisis encontró método inexistente de mensaje, vista aún no creada y expresiones redundantes; se corrigieron sin ignores/baselines. Un intento privado de escribir un generador falló por here-string antes de modificar archivos. No se suprimen pruebas ni errores.

## O. Revisión visual

Revisión ejecutada en **Chrome 157 real, modo headless mediante CDP**, sobre fresapos_test, en 1440×900, 1024×768, 768×1024 y 390×844. El motor Windows falló dos veces al iniciar; la alternativa permitió completar la revisión, sin instalar software. Evidencia: [23 capturas y reportes](qa/1.5d.2b/README.md).

Se comprobaron caja sin sesión, formulario, historial, vista de solo lectura y rechazo 403 del mesero; doble clic físico, foco de teclado, latencia con indicador y botones deshabilitados, rechazo por saldo insuficiente y recuperación de respuesta perdida. Chrome descartó una respuesta después del HTTP 200 del servidor; al recargar se reconciliaron estado y saldo sin duplicar. No se confunde esa pérdida de respuesta con una caída SQL: esta última tiene su prueba independiente descrita en H.

Resultado SQL del fixture: fondo 100.0000 + entrada 25.5000 − salida 5.1250 = **120.3750**, dos movimientos y dos auditorías, cero ventas/pagos, dos recibos completed y uno failed por saldo insuficiente. Las 23 capturas no presentan desbordamiento horizontal, errores JavaScript o botones visibles menores de 44 px. Se inspeccionaron visualmente formulario de escritorio, historial móvil y vista de lectura en tableta. Se cerraron Chrome/servidor QA y se eliminaron únicamente las tablas de fresapos_test con las salvaguardas activas: **cero tablas restantes**.

## P. Integridad

Comparación final satisfactoria de las **veinte tablas de datos originales**, por recuento y SHA-256 de sus filas completas, las nueve filas anteriores de migrations y diez archivos protegidos. Cero tablas anteriores o archivos protegidos modificados. Se conservan una venta real y sus dos líneas/precios, tres recibos previos, un administrador activo, cuatro roles, 44 permisos, dos categorías, dos productos, una caja y una sesión Cash. Las referencias confiables SQL continúan habilitadas.

Los métodos iniciales son configuración real nueva; se verificaron **cero movimientos y cero pagos** operativos. Conexión Windows, credenciales, .env/.env.testing, composer.json/lock y configuración protegida permanecen idénticos. [Informe de integridad](qa/1.5d.2b/finance-1.5d2b-final-integrity-report.json). Git conserva el trabajo previo sin commits/pushes.

Después de migrar se comprobó por HTTP real en 127.0.0.1:8080: /, /up, /api/v1/ping, /login y JavaScript Livewire responden 200; /cash, /pos y catálogo administrativo redirigen al login (302 sin sesión); los dos listados API financieros rechazan acceso anónimo con 401. No se utilizaron credenciales operativas para crear sesiones o movimientos de demostración.

## Q. Pendientes para 1.5d.3

Confirmación atómica del conjunto de cobros con autorización/revisión vigentes, persistencia financiera definitiva, contribución de efectivo neto a caja, folios y cierre de ventas. Permanecen fuera de alcance tickets, arqueo/cierre Cash, devoluciones, reembolsos, compensaciones, inventario, recetas y Android. No se abre una ruta de cobro anticipadamente.

Azure/cPanel siguen pendientes de credenciales autorizadas, ODBC/pdo_sqlsrv, TLS, firewall, permisos y ensayos de concurrencia/recuperación del proveedor. El scheduler del sistema debe ejecutar la política Core existente al desplegar. No se conectó a Azure ni se instaló software o dependencias nuevas. Al finalizar se detiene esta entrega.

## Informe final obligatorio

1. **Diagnóstico inicial:** Cash/Sales/Core previos funcionales, 455 pruebas y datos operativos reales; faltaban métodos, movimientos y preparación financiera.
2. **Modelos y servicios:** PaymentMethod, CashMovement, SalePayment; ManagePaymentMethods, ManageCashMovements, PreparePayments, FinancialStore y DTOs/contratos públicos. Cambios incrementales, sin reconstruir módulos.
3. **Métodos de pago:** cash, card, transfer y other persistidos con ULID estable; provisión idempotente y desactivación autorizada conservando historia.
4. **Cálculos y mezclas:** texto decimal(19,4), cambio y efectivo neto exactos; transferencias/otro requieren referencia y otro requiere permiso administrativo. Sin redondeos implícitos.
5. **Movimientos de efectivo:** entradas/salidas positivas con concepto, actor y sesión abierta original; sin edición o eliminación.
6. **Saldo esperado:** apertura + entradas − salidas, efectivo confirmado de Sales igual a cero en esta etapa. Sin salidas superiores al saldo ni desbordamiento decimal.
7. **Integración Cash/Sales:** contratos públicos, sesión vigente original y aritmética compartida Core; quote de Sales solo calcula, sin confirmar ni persistir pagos operativos.
8. **Idempotencia/concurrencia:** motor Core reutilizado, movimiento/Audit/recibo atómicos; nueve carreras SQL independientes y recuperación después de commit sin duplicación.
9. **Seguridad/auditoría:** permisos e identidad actuales, PIN y revocaciones comprobados; historial actual compartido y acceso histórico administrativo; un evento central por movimiento efectivo.
10. **API/Web:** cinco operaciones nuevas documentadas y panel dentro de /cash, intención estable, consulta de estado y preparación explícita de otra operación. POS previo conservado.
11. **SQL/migraciones:** respaldo COPY_ONLY/CHECKSUM verificado; migraciones Cash 000010 y Sales 000011 aplicadas, FK sin cascada y barrera SQL contra confirmación.
12. **Pruebas/aserciones:** 504/5216, exit 0; +49/+563 frente a base. Pint, Larastan 322 archivos, Composer y OpenAPI correctos; 255 respuestas reales contrastadas.
13. **Revisión visual:** Chrome real, 23 capturas y cuatro resoluciones, con teclado/carga, roles, doble clic y respuesta perdida. Datos de QA eliminados solo en fresapos_test.
14. **Integridad:** veinte tablas previas, nueve filas originales de migración y diez archivos protegidos intactos; venta real y dos líneas conservadas. Cero movimientos/pagos operativos de ejemplo.
15. **Habilitado:** consulta de métodos activos, entradas/salidas autorizadas, historial/saldo, reintento/reconciliación y preparación interna de cálculos.
16. **Bloqueado:** cobro definitivo, confirmación de pagos, folios, cierre de ventas/caja, arqueo, tickets y reembolsos.
17. **Pendiente 1.5d.3:** servicio transaccional definitivo de cobro, persistencia confirmada e integración de efectivo neto, folios/cierre con sus pruebas. Azure/cPanel requieren validación propia autorizada.
18. **Conclusión:** **ETAPA 1.5d.2b: ACEPTADA LOCALMENTE**, respaldada por pruebas, migraciones verificadas, integridad y QA real. Sin commits/pushes ni avance a 1.5d.3.
