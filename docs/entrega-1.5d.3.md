# Entrega 1.5d.3 — Cobro transaccional y folios diarios

17 de noviembre de 2026. **ETAPA 1.5d.3: ACEPTADA LOCALMENTE**. Regresión, QA real, respaldo verificado, migración incremental e integridad satisfactorios. Azure SQL/cPanel permanecen pendientes de validación propia.

## A. Línea base

Windows/PowerShell, PHP 8.3.36, Laravel 13.42.0, Livewire 4.4.8, Sanctum 4.4.0, SQL Server Express AUDITORIA 17.0.1155.3, Windows Authentication. Git main limpio, HEAD `465305e`; se conserva lo entregado en 1.5d.1/2a/2b y Sales. Se inspeccionaron informes, módulos, transacciones Core, idempotencia, pagos preparados, permisos, Audit, rutas, POS, migraciones y carreras SQL.

Línea base ejecutada antes de modificar código: **504 pruebas / 5216 aserciones, 9:12.780, 140 MB, exit 0**. Pint y Larastan nivel 5, 322 archivos, correctos. Logs privados checkout-1.5d3-baseline-* en .tools. Sin dependencias nuevas.

Al capturar las huellas iniciales ya había 24 tablas, **dos ventas operativas, tres líneas y un movimiento manual**. Durante el trabajo se observó actividad operativa adicional: dos movimientos al desplegar y cambios en accesos, recibos, auditoría y contenido de ventas. Se conservaron ambas referencias y se tomó otra inmediatamente antes de migrar; no se restauraron valores anteriores. Las pruebas usan exclusivamente fresapos_test y eliminan de su configuración la conexión operativa. No se cobra, cancela o modifica ninguna venta real como prueba.

## B. Arquitectura

SaleCheckout es el contrato público implementado por CheckoutSale en Application. Reutiliza IdempotentOperations, TransactionManager, SalesStore, IdentityAccess, SalesPolicy, ActiveCashSession, PaymentMethods, PaymentAllocation, CashMovements y auditoría centralizada. CheckoutStore persiste/lee pagos; DailyFolios asigna secuencia bajo la transacción externa. Ningún repositorio abre transacciones independientes.

Cash publica el puerto ConfirmedSalesCash, implementado por ConfirmedCashIncome en Sales. Cash consume su resultado sin importar modelos, SQL o clases privadas de Sales; Sales ya depende de contratos Cash. Se conserva la dirección de dependencias y Core no importa módulos funcionales. No hay otro libro de entradas de ventas ni motor de idempotencia paralelo.

Las fixtures históricas de Cash, que deliberadamente no instalan pagos, declaran ingreso cero en su puerto de prueba. Finance y Checkout restauran el adaptador SQL real. No se permite un adaptador productivo que silencie una migración faltante.

## C. Cobro transaccional

Autorización actual sin lock → appLock por contexto/UUID → transacción Core y autorización con lock de identidad → lectura de contexto original → caja/sesión → venta bloqueada → revisión If-Match → estado open y al menos una línea vigente → total histórico recalculado → pagos exactos → fecha UTC y folio → pagos confirmed → venta closed → validación de saldo/rango → auditoría y recibo Core → commit.

Se recalcula subtotal desde los precios/cantidades históricos y se exige coincidencia con subtotal/descuento/total persistidos. Una incoherencia se rechaza; no se corrigen silenciosamente líneas, precios o total. El cliente no puede imponer folio, fecha, actor, estado o total. Una venta, un cierre confirmado; otra clave sobre una venta cerrada produce conflicto. La revisión forma parte del hash de intención.

Los descuentos siguen sin servicio funcional de autorización/aplicación. DiscountApproval exige autorización previa; el adaptador productivo rechaza su ausencia con dependencia no disponible. No se añade permiso ni vía para aplicarlos. El caso de total cero se prueba con un descuento válido y autorización explícita de fixture aislada: sin pagos artificiales, sin ingreso, con folio e historial. Una venta sin líneas o con productos de precio cero sin descuento autorizado no permite ese atajo.

## D. Métodos

Se reutilizan los cuatro IDs/códigos cash/card/transfer/other; no se crean métodos nuevos. Deben estar activos al confirmar. Other requiere cash.administrative.execute vigente. Transfer y other conservan referencia corta obligatoria; tarjeta admite referencia opcional. No hay PAN, CVV, cuenta bancaria, terminal o transferencia electrónica.

Efectivo: importe aplicado positivo, recibido suficiente y cambio calculado en servidor. Solo el aplicado aumenta el efectivo físico; el recibido completo y el cambio no crean ingresos adicionales. Desactivar un método no borra historia ni impide replay de un cobro confirmado, siempre que el actor siga autorizado.

## E. Pago mixto

PaymentAllocation y DecimalAmount existentes mantienen aritmética decimal(19,4) exacta, sin float o redondeo implícito. La suma aplicada coincide exactamente con total al confirmar; no admite cobros parciales, excesivos, negativos o pagos de cero. Cada aplicación conserva método/actor/venta/sesión original; hasta veinte aplicaciones por operación. No efectivo carece de recibido/cambio.

La cotización visual se calcula en servidor y no confirma nada. Checkout revalida el conjunto dentro de la transacción; el resultado visual nunca es autoridad financiera.

## F. Folios

Formato YYYYMMDD0001; secuencia global por día comercial, independiente de actor, mesa y tipo. Tabla sales_daily_sequences con clave natural business_date y last_value entre 1 y 9999: no necesita ULID porque la fecha identifica inequívocamente el único contador del día. UPDLOCK/HOLDLOCK protege fila y hueco; incremento, pagos y cierre comparten rollback. No usa MAX, cantidad de ventas, memoria o contador por proceso.

POS_BUSINESS_TIMEZONE se lee exclusivamente desde config/pos.php. El usuario confirmó **America/Mexico_City**; se usa como valor predeterminado, sin editar .env. Zona inválida aborta. Se documenta la variable en .env.example. El navegador no determina el día y las fechas persistidas permanecen UTC. Después de 9999 se rechaza el cierre; no se expande silenciosamente el formato.

Se conserva el índice único filtrado sales_unique_folio ya existente. SQL y el modelo impiden modificar un cierre. Un rollback no confirma ni consume folio; un folio confirmado no se reutiliza. No existe reapertura productiva.

## G. Cash

FinancialStore conserva su contrato y ahora agrega efectivo neto confirmado de Sales. Fuente: applied_amount de pagos cash, status confirmed, confirmed_at presente y venta closed, por sesión original. Tarjetas, transferencias, borradores y ventas abiertas aportan cero. SQL agrega en precisión ampliada y se valida salida decimal(19,4).

Saldo = apertura + entradas manuales − salidas manuales + efectivo neto confirmado. No se insertan movimientos manuales para representar cobros. Apertura y cambio no se cuentan otra vez. La validación y el saldo usan los mismos locks de sesión que entradas/salidas existentes; un saldo fuera de rango revierte checkout completo. Caja muestra el ingreso confirmado separado de movimientos manuales.

## H. Sales e historia

Se conservan ULID, tipo, mesa, creador, sesión, líneas, precios, cantidades, notas, subtotal, descuento y total. Se añaden closed_by y pagos confirmados; folio/closed_at ya existían. Los DTOs y codec admiten pagos con valores predeterminados para recibos anteriores. No se recalcula un histórico con Catalog.

Cerrar una mesa conserva su relación histórica y la libera mediante el índice/consulta de disponibilidad existente basado en estados activos. Mostrador desaparece de abiertas y permanece consultable por ID/folio. No se abre otra venta automáticamente.

## I. Idempotencia

Core existente coordina UUID v4, contexto del actor, sales.checkout, destino, pagos canónicos y revisión. Reautoriza antes de consultar/reproducir. Mismo intento devuelve folio/pagos/fechas originales; clave con datos distintos produce IDEMPOTENCY_KEY_CONFLICT; una intención simultánea activa produce OPERATION_IN_PROGRESS. No se duplica pago, cierre, contador o auditoría.

Se conservan reintentos selectivos, reconexión, recuperación de recibo, TTL/compactación y límites anteriores. Una respuesta perdida se resuelve por estado/consulta y replay de la misma intención. La sesión Web guarda su metadata antes del negocio. No se introduce otro recibo en Sales o Cash.

## J. Seguridad

API exige Sanctum y acceso vigente; servicio exige identidad activa, contraseña cambiada, sales.view, sales.close y cash.view según SalesPolicy. Other agrega permiso administrativo Cash. No se asignan roles/permisos ni existe bypass administrador. PosAccess conserva además los permisos necesarios para acceder al POS.

El alcance de lectura/escritura compartido del establecimiento conserva las reglas Sales existentes: no se inventa propiedad exclusiva por creador. Un UUID no concede acceso. El transporte revalida usuario/sesión, y los snapshots bloquean actor/venta/clave/revisión/recibo. Se prueba cambio por PIN real, empleado anterior, revocación y usuario mesero sin permiso de cobro.

## K. Auditoría

Un solo evento funcional sales.checkout.confirmed describe cobro, cierre y folio, con actor real y record_id de venta. SaleCheckoutAudit cerrado contiene sesión Cash, folio, total, efectivo aplicado y número de pagos; Audit usa su change_data existente. No se copian referencias libres, UUID, hash, credenciales o datos bancarios. Fallo Audit revierte pagos/cierre/folio/recibo; replay no agrega eventos.

Core conserva el registro durable de rechazos definitivos y su evento central de conflicto de clave. No se presentan recibos failed como cobros confirmados. SaleChanged posterior al commit es secundario: un consumidor o logger fallido nunca revierte ni presenta como fallido un cobro confirmado. No se implementa outbox durable en esta etapa.

## L. API

POST /api/v1/sales/{id}/checkout con payments, Idempotency-Key UUID v4 e If-Match de la venta consultada. Cada pago recibe payment_method_id, applied_amount como texto, received_cash opcional y reference opcional según método. Campos desconocidos se rechazan, incluso dentro de pagos. Éxito 200 inicial/replay mediante el sobre existente, con venta, folio, total, pagos, cambio, closed_by, fecha UTC y estado final.

GET de venta devuelve el cierre financiero cuando existe; GET /sales admite filtro exacto folio y conserva paginación/filtros. Los recursos solo exponen campos permitidos. Errores 400/401/403/404/409/422/429/500/503 saneados, no-store, X-Request-Id y meta.request_id. SensitiveErrors protege también sales.checkout en Livewire. Debug true/false no filtra excepciones internas.

OpenAPI final: **52 operaciones, 18 Sales, ocho Cash; 281 respuestas reales de 42 operaciones validadas, cero errores/advertencias**. La validación formal cubre las 52 operaciones; no se inventan muestras para las diez sin respuesta exportada. [Informe](qa/1.5d.3/checkout-1.5d3-openapi-final.json).

## M. POS Web

CheckoutPanel se monta dentro del POS existente para la venta seleccionada y el actor autorizado, una vez aplicada la migración. Permite Cobrar, revisar total vigente, métodos mixtos, importes, recibido y cálculo de cambio en servidor. Confirmar invoca SaleCheckout; no hay sistema paralelo en Livewire. Controles disabled son UX y la transacción sigue siendo la protección real.

UUID y contexto bloqueados, continuidad de sesión, consulta de estado, replay y preparación explícita tras rechazo definitivo. Doble clic en un paquete realiza un efecto; el snapshot firmado anterior sigue usando la misma intención. Tras respuesta perdida/recarga consulta el cierre persistido. Muestra folio, desglose y cambio; notifica al POS para actualizar venta/listas/mesa. No imprime ticket.

## N. SQL Server y respaldo

Única migración nueva: 000012_enable_sale_checkout, incremental sobre 000011 conservada. Crea contador diario, closed_by con FK Users y unicidad (id,cash_session_id); añade FK compuesta de pagos a venta/sesión original. Amplía la CHECK de pagos para admitir prepared o confirmed con coherencia de fecha, efectivo y referencia. No reescribe pagos o líneas anteriores.

Tres triggers impiden modificar/borrar ventas con folio, alterar sus líneas o modificar/borrar pagos confirmados. Índice único de folio y restricciones existentes permanecen. Sin cascadas; rollback restringido a _test y destinado a limpiar fixtures antes de retirar el esquema 000011. No se ejecuta rollback operativo.

Después de pruebas y QA se creó el respaldo real **fresapos_pre_1_5d3_20261117_202739Z_a413575a.bak**, fuera del repositorio, **9 519 104 bytes**, COPY_ONLY/CHECKSUM, sin sobrescribir archivos. RESTORE VERIFYONLY WITH CHECKSUM, STOP_ON_ERROR pasó a las **20:27:39 UTC**. Metadata/ruta privada en .tools/checkout-1.5d3-backup.json. VERIFYONLY no equivale a una restauración de ensayo; no se afirma haberla realizado.

Se aplicó solo `2026_11_17_000012_enable_sale_checkout`, **169.97 ms, lote 11 Ran**. Las once filas de migraciones previas se conservaron. La base pasó de 24 a 25 tablas, con contador vacío y closed_by NULL para las ventas originales; no hubo cobros de demostración operativos. Dos FK nuevas confiables y tres triggers habilitados comprobados. Sin migrate:fresh/db:wipe ni modificación de configuración o credenciales locales.

## O. Calidad

Suite completa final: **547 pruebas / 5831 aserciones, 10:46.683, 150 MB, exit 0**, sin errores, fallos u omisiones. Frente a la línea base: **+43 pruebas, +615 aserciones, +1:33.903 y +10 MB**, conservando los 504 casos anteriores. Larastan nivel 5: **334 archivos sin errores**, frente a 322 iniciales. Pint y Composer validate --strict/check-platform-reqs correctos, sin dependencias nuevas.

Resultados focalizados ejecutados: CheckoutTest 18/147 antes de retirar una aserción redundante; HTTP 4/161; Livewire ampliado 5/64; integridad SQL 4 casos incluidos en una ejecución de siete casos UI/integridad con 57 aserciones; concurrencia 12/222. Tras corregir el reloj del fixture, se repitieron las doce carreras: 12/222, 40.375 s, correctas. En la suite final la carrera de edición modifica realmente la cantidad de una línea de 1 a 2, dejando el checkout con revisión anterior rechazado y sin cierre/folio/pagos.

| Comando PowerShell desde la raíz | Resultado real final |
| --- | --- |
| `.\scripts\local.cmd composer test` | 547 pruebas / 5831 aserciones; COMPOSER_PROCESS_TIMEOUT=1800 solo en el proceso, restaurado al terminar |
| `.\scripts\local.cmd composer lint` | Passed, repetido después del despliegue |
| `.\scripts\local.cmd composer analyse` | Nivel 5, 334 archivos, cero errores |
| `.\scripts\local.cmd composer validate --strict` | Válido, exit 0 |
| `.\scripts\local.cmd composer check-platform-reqs` | Requisitos satisfechos, exit 0 |
| `powershell.exe -NoProfile -ExecutionPolicy Bypass -File scripts/validate-openapi.ps1 -Responses .tools/checkout-1.5d3-all-responses.json -Report .tools/checkout-1.5d3-openapi-final.json` | 52 operaciones, 281 respuestas, cero errores/advertencias |
| `.\scripts\local.cmd php artisan migrate --path=Modules/Sales/database/migrations/2026_11_17_000012_enable_sale_checkout.php --no-interaction` | 000012 Ran, lote 11 |
| `.\scripts\local.cmd php artisan migrate:status` | Doce migraciones aplicadas |
| `.\scripts\local.cmd php artisan config:show pos` | America/Mexico_City |
| `git diff --check` | Sin errores; avisos informativos CRLF/LF |

Evidencia privada en .tools/checkout-1.5d3-tests-final.log, baseline-*, analyse-final.log, lint-final.log, quality-composer.log, quality-platform.log, migration.log y doce race-*.json, con prefijo checkout-1.5d3 cuando se abrevia. Reportes seguros publicados en [QA](qa/1.5d.3/README.md).

Fallos intermedios conservados: ruta nueva ausente en lista de prueba; namespace incorrecto de un usuario de fixture; lectura env en configuración modular detectada por Larastan y movida a config/pos.php; expresiones redundantes corregidas sin ignores. El primer ensayo de carreras cruzando día expiró tokens del fixture: se extendió solo su vigencia de prueba, manteniendo autenticación real. En deadlock SQL eligió inicialmente el bloqueador de fixture como víctima; SET DEADLOCK_PRIORITY mediante ejecución directa permitió comprobar 1205/reintento del checkout real. Un filtro por dataset numérico no ejecutó casos; no cuenta como validación.

El primer generador OpenAPI produjo anclas YAML duplicadas. La revisión automática rechazó reconstruir desde HEAD por riesgo de sobrescribir cambios previos. Se preservó el documento actual y se corrigieron solo nombres de anclas/referencias de los bloques nuevos; la validación formal posterior pasó. No se pidió autorización para descartar trabajo ni se descartaron cambios del usuario.

Primera regresión integral: **547 pruebas / 5814 aserciones, 11:54.064, 152 MB, un fallo**, conservado en tests-full-first.log. El reloj fijo de un worker intentaba cerrar la sesión del fixture a las 20:00 UTC, antes de su apertura real después de esa hora; SQL rechazó correctamente la CHECK. Se fijó también la creación del fixture a las 18:00 UTC, sin tocar la restricción o lógica productiva. Las doce carreras posteriores y la suite integral final pasaron. Los fallos de Pint durante la edición se corrigieron y el control final volvió a pasar.

## P. Revisión visual

Revisión completada en **Chrome real headless/CDP, 32 capturas**, a 1440×900, 1024×768, 768×1024 y 390×844. Computer-use falló dos veces al iniciar el sandbox Windows; la alternativa usó servidor, perfil, cookies y datos aislados sobre fresapos_test. Sin dependencias instaladas: el transporte CDP local utilizó la biblioteca estándar de Python ante la ausencia del módulo websocket previo.

Se comprobaron venta abierta, efectivo/cambio, tarjeta, transferencia, mezcla, validaciones, teclado, carga, doble clic, pérdida de respuesta después del HTTP 200, recuperación, folio, mesa libre, mesero sin botón, permisos retirados y Cash cerrado por fixture. Cero errores JavaScript, desbordamientos horizontales o botones visibles menores de 44 px en las 32 mediciones. Se inspeccionaron visualmente escritorio, tableta y móvil. [Capturas y reportes](qa/1.5d.3/README.md).

SQL de QA: ocho ventas ficticias, **cinco cierres, seis pagos, cinco auditorías, folios 202611170001–202611170005**, cero entradas manuales y mesa libre. Apertura 100.0000 + efectivo neto 440.0000 = **540.0000**; tarjeta/transferencia/borradores aportan cero. Doble clic y recuperación no duplican efectos. La transición Cash closed ocurrió solo en fresapos_test, sin habilitar cierre de caja. Procesos cerrados y tablas de QA retiradas: cero restantes.

## Q. Integridad

Comparación final satisfactoria: **23/23 tablas de datos anteriores** idénticas por recuento/SHA-256 respecto al instante previo al despliegue; once filas originales de migrations intactas y **10/10 archivos protegidos** idénticos. Se proyectaron las columnas originales de sales_sales, pues añadir closed_by NULL cambia la representación JSON completa sin alterar los valores históricos. No se restauraron filas del usuario para forzar igualdad.

Actividad entre la primera huella y el despliegue: audit_security_events 41→47, auth_access_sessions 9→10, cash_movements 1→2 e idempotency_records 6→9; sales_sales mantuvo dos filas con cambio de contenido. Se preservó esa actividad operativa. Al verificar el despliegue permanecían **dos ventas, tres líneas, dos movimientos, cero pagos confirmados y cero folios/contadores utilizados**. Los métodos, sesión Cash, catálogo, usuarios/administrador y configuración anterior permanecen intactos respecto a la referencia previa. [Integridad](qa/1.5d.3/checkout-1.5d3-integrity-report.json) y [actividad conservada](qa/1.5d.3/checkout-1.5d3-operative-activity.json).

HTTP real posterior al despliegue en 127.0.0.1:8080: /, /up, /api/v1/ping, /login y JS Livewire devuelven 200; POS/Cash/Catalog administrativo anónimos redirigen al login (302); API Sales/Cash y POST checkout sin token responden 401. [Reporte](qa/1.5d.3/checkout-1.5d3-health.json). Login autenticado, POS y Cash se comprobaron con fixtures y Chrome, sin usar credenciales operativas. .env/.env.testing, conexión Windows, administrador, roles/permisos, Composer y configuración protegida se conservaron. Sin commits/pushes.

## R. Pendientes

Arqueo/cierre de caja, tickets, reapertura pagada, correcciones/compensaciones financieras autorizadas, devoluciones, reembolsos, descuentos operativos, motivos de cancelación/retirada, inventario, recetas, división/traslado de mesas y Android siguen fuera de alcance. No se avanza automáticamente a 1.5d.4.

Azure SQL/cPanel siguen pendientes de credenciales autorizadas, ODBC/sqlsrv/pdo_sqlsrv, TLS, firewall, permisos, respaldo, PHP/ionCube y ensayos reales de concurrencia/recuperación. Solo se valida Express local. Scheduler y retención Core mantienen su configuración existente.

## Informe final obligatorio

1. **Diagnóstico:** base funcional de 504 pruebas, módulos existentes y datos reales; se reutilizaron contratos, cálculos y motor durable.
2. **Implementación:** CheckoutSale/SaleCheckout, CheckoutStore, DailyFolios, ConfirmedSalesCash/ConfirmedCashIncome, DTO de auditoría y CheckoutPanel. Sin dependencias Composer nuevas.
3. **Flujo:** autorización/revisión actual, sesión original abierta, total histórico, pagos, folio, cierre, Audit y recibo en una transacción Core.
4. **Métodos/mezclas:** efectivo con cambio, tarjeta, transferencia y otro autorizado; aplicado exacto, sin parciales o excesos. Referencias restringidas, sin integración bancaria.
5. **Folios:** globales diarios YYYYMMDD0001, zona America/Mexico_City confirmada, contador bloqueado y límite explícito 9999; rollback/replay no duplican secuencia.
6. **Cash:** apertura + entradas − salidas + efectivo aplicado confirmado; sin entradas manuales duplicadas o suma de cambio/tarjeta/transferencia.
7. **Sales:** precios/líneas históricos conservados, estado closed y closed_by; mesa liberada, mostrador retirado de abiertas, historial por ID/folio.
8. **Idempotencia:** Core UUID v4 y hash canónico reutilizados, reautorización y recuperación del resultado original.
9. **Concurrencia:** doce carreras con procesos HTTP/SPID independientes; mismo/diferentes UUID, ventas simultáneas, cantidades, Cash, mezcla, días, rollback, pérdida de respuesta y deadlock real recuperado.
10. **Seguridad:** permisos existentes y roles conservados; cajero/administrador autorizados, mesero rechazado, PIN real, snapshot previo, revocación e intención pendiente comprobados. Supervisor conserva su capacidad sales.close según la matriz vigente.
11. **Auditoría:** un evento central por cierre con venta, actor, sesión, total y folio; fallo revierte todo y replay no duplica.
12. **API:** checkout REST y recursos históricos, filtro exacto por folio, validación estricta y errores saneados en debug true/false.
13. **POS Web:** cobro, mezcla, cálculo de cambio, procesamiento, consulta/reintento y confirmación con folio dentro del POS existente.
14. **SQL Server:** respaldo COPY_ONLY/CHECKSUM verificado y migración incremental 000012, lote 11; dos FK confiables y tres triggers habilitados.
15. **Pruebas:** 547/5831 correctas, +43/+615 respecto a base; Pint, Larastan nivel 5/334 archivos, Composer y plataforma correctos. Primera regresión fallida documentada y corregida, sin retirar pruebas.
16. **OpenAPI:** 52 operaciones y 281 respuestas reales de 42 operaciones contrastadas, cero errores/advertencias.
17. **Visual:** Chrome real, 32 capturas, cuatro resoluciones; saldo QA 540.0000, cinco cierres/seis pagos/cinco auditorías, mesa libre y recuperación sin duplicación.
18. **Integridad:** 23 tablas previas y diez archivos protegidos intactos desde el despliegue; dos ventas y tres líneas originales conservadas, con dos movimientos del usuario. Cero pagos/folios operativos al verificar; ninguna venta real cobrada como prueba.
19. **Habilitado:** cobro autorizado de ventas abiertas, pagos mixtos, folios, consulta histórica, liberación de mesa y saldo de efectivo confirmado.
20. **Bloqueado:** arqueo/cierre de caja, reaperturas pagadas, reembolsos, cancelación financiera, descuentos operativos sin autorización funcional y tickets físicos.
21. **Pendientes:** esos flujos específicos y validación propia de Azure SQL/cPanel; no hay confirmaciones adicionales pendientes para el alcance local ejecutado.
22. **Conclusión:** **ETAPA 1.5d.3: ACEPTADA LOCALMENTE**, con evidencia real de pruebas, QA, respaldo, migración e integridad. Sin commits/pushes ni avance a 1.5d.4.
