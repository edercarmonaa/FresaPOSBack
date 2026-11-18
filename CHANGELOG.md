# Registro de cambios

## [Sin publicar] — Etapa 1.6a

- Catálogo Sales de motivos autorizados con código/tipo inmutables, administración Web/API y ausencia de semillas operativas inventadas.
- Retiro histórico de líneas y cancelación no financiera de ventas abiertas/pausadas; permisos reales, Cash original, revisión y UUID v4 Core, auditoría atómica y protección SQL.
- Recuperación del deadlock SQL anidado de Laravel y sincronización del panel POS después de actualizar el ticket.
- Doce carreras SQL/HTTP, cuatro resoluciones Chrome, doble clic, respuesta perdida y cambio por PIN; 48 pruebas nuevas sin eliminar las 593 anteriores.
- [Informe A–R y resultados definitivos](docs/entrega-1.6a.md), [evidencia](docs/qa/1.6a/README.md) y OpenAPI de 62 operaciones. Sin commits, pushes ni despliegues externos.

## [Sin publicar] — Etapa 1.5d.5

- Aceptación integral por API y Chrome real del ciclo Cash–Sales, cinco modalidades de pago, arqueo ciego y segundo turno; sin reconstruir módulos ni cambiar negocio.
- Cuatro pruebas adicionales de ciclo financiero, revocación, compactación y consultas; 593 pruebas / 6832 aserciones, Pint y Larastan nivel 5 (349 archivos) correctos.
- Sonda CLI de solo lectura para invariantes financieras; 26 tablas operativas y diez archivos protegidos idénticos, sin migraciones nuevas.
- OpenAPI: 57 operaciones, 388 respuestas reales de las 57; 33 reportes de carreras financieras actuales y 76 capturas en cuatro resoluciones.
- [Informe](docs/entrega-1.5d.5.md) y [evidencia](docs/qa/1.5d.5/README.md). Scheduler del sistema, Azure SQL/cPanel y observaciones visuales no bloqueantes documentados. Sin commits, pushes o despliegues.

## [Sin publicar] — Etapa 1.5d.4

- Arqueo ciego, diferencias exactas, conciliación e historial inmutable por sesión; observación obligatoria sin revelar el saldo mediante validaciones condicionales.
- Cierre transaccional con Core, saldo Cash existente, resumen Sales por contrato, UUID4 durable y auditoría atómica; bloqueo por ventas pendientes y actividad posterior al cierre.
- Cinco operaciones REST, OpenAPI y panel Livewire dentro de /cash con recuperación, historial, filtros y apertura posterior expresa.
- 589 pruebas / 6425 aserciones; doce carreras SQL, controles de calidad correctos y 38 capturas Chrome aisladas en cuatro resoluciones. Normalización histórica final verificada con 24/146 y 6/47 focalizadas.
- OpenAPI: 57 operaciones y 315 respuestas reales validadas, sin errores ni advertencias.
- Respaldo con checksum verificado, migraciones 000013/14 lote 12 y 24 tablas de datos previas/diez archivos protegidos intactos. Sin cierres operativos de prueba.
- [Informe](docs/entrega-1.5d.4.md) y [evidencia](docs/qa/1.5d.4/README.md). Sin commits/pushes ni avance a 1.5d.5.

## [Sin publicar] — Etapa 1.5d.3

- Checkout transaccional con Core, pagos confirmados, cierre y auditoría atómicos, UUID v4 y revisión vigente.
- Efectivo/cambio, tarjeta, transferencia, otro autorizado y mezclas exactas; efectivo neto integrado en Cash sin entradas manuales duplicadas.
- Folios globales diarios YYYYMMDD0001 con contador SQL transaccional y zona comercial America/Mexico_City.
- Historial por folio, checkout REST y panel dentro del POS existente; conservación de contexto y recuperación de respuesta perdida.
- Suite completa: **547 pruebas / 5831 aserciones**; doce carreras SQL independientes, deadlock 1205 recuperado y 32 capturas Chrome aisladas.
- OpenAPI: 52 operaciones y 281 respuestas reales validadas, cero errores/advertencias.
- Respaldo verificado, migración 000012 aplicada y 23 tablas de datos anteriores intactas respecto al despliegue, conservando actividad operativa previa.
- [Informe](docs/entrega-1.5d.3.md). Sin arqueo/cierre de caja, reembolsos, tickets, commits/pushes o avance a 1.5d.4.

## [Sin publicar] — Etapa 1.5d.2b

- Cuatro métodos persistentes con ULID estable, provisión idempotente y desactivación con historial.
- Preparación interna de pagos y validación exacta de efectivo, cambio y mezclas; confirmación bloqueada también por SQL.
- Entradas/salidas Cash autorizadas, saldo bajo bloqueo SQL, recibos durables Core y auditoría atómica.
- Cinco operaciones API, OpenAPI contrastado con 255 respuestas reales y panel Livewire dentro de Caja.
- Suite completa: 504 pruebas / 5216 aserciones; nueve carreras SQL y 23 capturas Chrome aisladas.
- Respaldo verificado, dos migraciones incrementales aplicadas y veinte tablas previas intactas.
- [Informe](docs/entrega-1.5d.2b.md). Sin cobros definitivos, folios, cierres, commits/pushes o avance a 1.5d.3.

## [Sin publicar] — Etapa 1.5d.2a

- Idempotencia durable reusable en Core, UUID v4, hashing canónico y exclusión SQL por intención.
- Sales real con Cash: apertura, agregado, cantidades/notas y pausa/recuperación; replay autorizado sin duplicados.
- Estado autenticado, retención segura, compactación horaria y continuidad del POS tras respuesta perdida.
- Pruebas SQL/HTTP/Livewire, deadlock real y QA Chrome en cuatro tamaños.
- [Informe y límites](docs/entrega-1.5d.2a.md). Sin cobros, cierres, folios, commits o avances a 1.5d.2b.

## [Sin publicar] — Etapa 1.5d.1

- Caja única/sesiones ULID, fondo decimal(19,4), permisos actuales y auditoría transaccional.
- Apertura con recibo SQL durable, replay autorizado e índice contra sesiones simultáneas.
- Contrato Cash real en transacción Sales; FK incremental sin reescribir historia.
- Tres endpoints Cash, pantalla /cash, navegación/estado POS y OpenAPI ampliado.
- Pruebas SQL/HTTP/Livewire, seis carreras SQL independientes y 22 capturas Chrome aisladas.
- [Informe/límites](docs/entrega-1.5d.1.md). Apertura/agregado Sales bloqueados por su idempotencia pendiente. Sin pagos, movimientos, arqueos, cierres ni commits/pushes.

## [Sin publicar] — Etapa 1.5c

- Punto de venta Web en /pos con Blade/Livewire, navegación y empleado activo; catálogo paginado, búsqueda/categorías e imágenes.
- Mostrador abierto/pausado, mesas y panel de venta con importes históricos; formularios delegados a Sales, sin carrito ni cálculos financieros de navegador.
- Revisión atómica existente, bloqueo de objetivos del snapshot, sesión web/CSRF/PIN y permisos actuales. Apertura y edición siguen condicionadas a Cash; idempotencia y motivos no se simulan.
- Diseño de escritorio/tablets/móvil con panel táctil, foco accesible, avisos de conflicto/conexión/sesión y conservación de notas.
- Pruebas SQL/Livewire y revisión real en Chrome con 29 capturas privadas de datos de fixture, publicadas en docs/qa/1.5c.
- [Informe y límites operativos](docs/entrega-1.5c.md). Sin nuevas tablas, pagos, cierres, commits o avance a 1.5d.

## [Sin publicar] — Etapa 1.5b

- API de Sales: 17 operaciones de mesas, consulta de ventas/líneas, cantidades, notas, pausa/recuperación y cancelación no financiera condicionada.
- Requests estrictos, Resources sobre DTOs, filtros/paginación SQL, permisos actuales y bearer vigente; sin precios/totales/actor del cliente.
- Precondición If-Match atómica mediante huella de contenido y transacción existente, sin rowversion ni cambios de esquema; mesa ocupada no desactivable.
- Apertura/agregado bloqueados sin idempotencia durable y Cash real; contratos de integración y motivos sin implementaciones ficticias productivas.
- OpenAPI ampliado y contrastado con respuestas reales; pruebas de kernels HTTP/SPID independientes y regresión existente.
- [Informe, resultados y límites operativos](docs/entrega-1.5b.md). Sin UI de ventas, cobros, commits o avances a 1.5c.

## [Sin publicar] — Etapa 1.5a

- Fundamentos de Sales: ventas de mostrador/mesas, líneas históricas, DTOs, contratos y repositorios Eloquent; sin POS ni endpoints nuevos.
- Cantidades enteras con máximo global inicial de 100 confirmado por el usuario, notas normalizadas y aritmética decimal exacta sin float.
- Instantáneas/consolidación por precio, nombre, categoría y nota; estados abiertos/pausados, cancelación sin cierre y retirada condicionada a motivos autorizados.
- Contrato público de Cash con adaptador que rechaza operaciones hasta disponer de sesión real; sin caja ficticia ni folios/pagos anticipados.
- Tres tablas incrementales con siete FK NO ACTION e índices filtrados; concurrencia verificada con dos procesos/sesiones SQL y bloqueo real.
- Auditoría existente ampliada de forma aditiva y eventos secundarios después de commit; permisos/credenciales intactos.
- Respaldo COPY_ONLY/CHECKSUM verificado y migración operativa lote 5 aplicada, tres tablas vacías y datos anteriores conservados.
- Calidad: **343 pruebas / 2736 aserciones**, Pint, Larastan nivel 5 y Composer correctos; OpenAPI y 40 rutas propias conservados.
- [Informe técnico y límites para 1.5b](docs/entrega-1.5a.md). No se avanza a la siguiente etapa.

## [Sin publicar] — Etapa 1.4d

- Validación formal OpenAPI 3.1 reproducible con openapi-spec-validator 0.9.0, rutas reales y esquemas de respuestas de aceptación.
- Tooling Python aislado y fijado para desarrollo; comando composer openapi, sin paquetes nuevos del backend.
- Corregida pérdida de no-store en errores JSON renderizados por excepciones, manteniendo sobres y códigos existentes.
- Errores de formularios Catalog asociados a sus inputs mediante ARIA, incluidos aliases de validación del dominio.
- Cinco casos nuevos de aceptación: ciclo API, permisos/revocación, auditoría UTC, contratos para Sales y accesibilidad del HTML.
- Cierre local: 273 pruebas / 2454 aserciones; Pint, Larastan nivel 5 y Composer correctos. OpenAPI: 25 operaciones coincidentes y 34 respuestas reales de Catalog, sin errores ni advertencias.
- Revisión visual completada mediante Chrome headless tras fallar el runtime interactivo: cuatro tamaños, 44 capturas, teclado/foco, carga/vista previa, doble clic y confirmaciones. Datos operativos intactos y fixtures retirados. [Informe de cierre](docs/entrega-1.4d.md).

## [Sin publicar] — Etapa 1.4c

- Imágenes principales opcionales de catálogo: contenido JPEG/PNG/WebP hasta 5 MiB, WebP optimizado con GD, transparencia y ocho orientaciones EXIF.
- Versiones ULID nuevas por sustitución; URLs mediante Filesystem y referencias disk/key existentes, sin migraciones ni nuevas librerías Composer.
- Coordinador de imagen/SQL con auditoría centralizada, rollback seguro, limpieza tras commit y journal privado con reconciliación controlada.
- API multipart POST y DELETE de imagen; formulario Livewire con vista previa privada, permisos, validación y conservación de valores.
- Extensiones locales GD/EXIF habilitadas y límites PHP documentados; publicación Windows comprobada y reglas Apache preparadas.
- Validación final: 268 pruebas / 2209 aserciones, Pint, Larastan nivel 5 y Composer correctos; datos y diez archivos protegidos intactos.
- [Informe técnico](docs/entrega-1.4c.md). No se avanza a 1.4d.

## [Sin publicar] — Etapa 1.4b

- Trece operaciones REST de categorías/productos con Sanctum, Form Requests, Resources y sobres Core existentes.
- Pantallas Blade/Livewire en el layout actual, navegación Catálogo por capacidades, filtros y paginación SQL de 10/25/50/100.
- Creación, edición, estados, eliminación lógica y papelera/restauración de productos; confirmaciones y precios sin floats.
- Políticas y servicios de 1.4a reutilizados; auditoría exclusivamente en el servicio, sin cambios de esquema o dependencias.
- Pruebas SQL Server de HTTP/API/Livewire, CSRF, sesiones revocadas, permisos, decimales y confidencialidad con ambos modos de debug.
- OpenAPI existente actualizado y [entrega documentada](docs/entrega-1.4b.md). Procesamiento de imágenes reservado para 1.4c.

## [Sin publicar] — Etapa 1.4a

- Catalog: categorías/productos ULID, decimal(19,4), relaciones SQL, SoftDeletes e índices de consulta.
- Servicios autorizados, repositorios específicos, DTOs inmutables y contrato de producto vendible para Sales.
- Restauración activa con categoría válida y búsquedas por prefijo de palabra con collation CI_AI_SC.
- Referencia opcional disk/key WebP, sin subida de archivos ni endpoints de catálogo.
- Auditoría existente ampliada con metadatos cerrados de campos/precios y migración aditiva nullable.
- Respaldo operativo verificado antes de aplicar las dos migraciones; datos y credenciales anteriores conservados.
- 200 pruebas, 1516 aserciones; Pint, Larastan nivel 5 y Composer correctos. [Informe](docs/entrega-1.4a.md).


## [Sin publicar] — Etapa 1.3c validada el 12 de noviembre de 2026

- Validación real Windows/SQL Server: 135 pruebas, 1256 aserciones, Pint y Larastan nivel 5 correctos.
- Bloqueos recuperables de cinco intentos/cinco minutos y administración de sesiones API/Livewire existentes comprobados.
- Respaldo COPY_ONLY/CHECKSUM verificado antes de aplicar la migración incremental Audit.
- Corregida la vigencia de sesiones PIN tras perder permisos operativos y la revalidación bajo lock durante el cambio de empleado.
- Configuración de retención centralizada en config y expectativas de rutas/diagnósticos actualizadas sin retirar pruebas.
- Administrador, credenciales, roles, permisos y datos existentes conservados; sin commits/pushes de esta validación.
- [Informe de aceptación](docs/entrega-1.3c.md).


## [Sin publicar] — Etapa 1.3b

- Sanctum 4.4.0, tokens ULID con hash, expiración y revocación.
- Auth: login Blade/Livewire por username, logout, cambio obligatorio de contraseña y protección Web/API.
- Registro de accesos, timeout Web de 30 minutos, límites configurables y cierre de sesiones propias.
- PIN hasheado y cambio de empleado en contexto de terminal autorizado, sin herencia de permisos.
- Contrato público de identidad en Users, proveedor activo y evento de revocación tras commit.
- Migración incremental de tres tablas Auth; sin usuarios ni credenciales operativas.
- Protección de secretos en errores API, HTML de autenticación y Livewire auth.login.
- Pruebas SQL aisladas, documentación y OpenAPI actualizado. [Informe](docs/entrega-1.3b.md).


## [Sin publicar] — Etapa 1.3a

### Añadido

- Users: modelos ULID, estados, borrado lógico, servicios, DTO público y Policies por permisos.
- Spatie Laravel Permission 8.5.0 y package-tools 1.93.3, cuatro roles y 44 capacidades iniciales.
- Migración SQL incremental con FK sin cascada, índices y username normalizado protegido en SQL.
- Lock transaccional para seguridad y protección del último administrador activo.
- Historial seguro de tres contraseñas, validación Unicode y prevención de borrado físico.
- Seeder idempotente que conserva asignaciones y comando interactivo para el primer administrador.
- Pruebas SQL reales, denegación de privilegios y documentación de matriz/aprovisionamiento.

### Cambiado

- El proveedor Eloquent nativo utiliza el modelo del módulo Users; no se implementa autenticación.
- La prueba de registro modular exige la migración real en Users y mantiene vacíos los otros módulos.
- fresapos incorpora únicamente el esquema y catálogo de seguridad, sin usuarios operativos.

## [Sin publicar] — Etapa 1.2c

### Añadido

- API v1 organizada por módulo, con un único prefijo api y endpoint técnico ping.
- Contratos de respuesta y catálogo tipado en Core, mensajes en español e inglés.
- Identificador ULID de solicitud validado, cabecera y contexto de logs con limpieza posterior.
- Manejo seguro de errores JSON, validación anidada y mapeo de excepciones por registro.
- Limitador mínimo de API y pruebas de errores, logs, debug, web y Livewire.
- Documentación de API y OpenAPI manual; validación YAML pendiente por ausencia de parser.

### Cambiado

- Bootstrap y proveedor de Core conectan la infraestructura sin alterar .env, SQL ni CORS.
- Sin paquetes nuevos, autenticación funcional ni endpoints de negocio.

## [Sin publicar] — Etapa 1.2b

### Añadido

- Contrato transaccional de Core y adaptador de Laravel con un intento y callbacks tras commit.
- Base independiente fresapos_test, configuración TEST_DB_* y preparación idempotente local.
- Guardas previas al DDL, comprobación de DB_NAME y rechazo de conexiones ajenas en la integración.
- Pruebas SQL reales de ULID nativo nchar(26), serialización, FK, decimal, UTC, índices, commit, rollback y eventos posteriores.
- Documentación de persistencia, pruebas, Azure y criterios de reintentos/idempotencia sin implementarlos.

### Cambiado

- Ejemplos de entorno con marcadores y credenciales distintas para staging/producción; .env local preservado.
- PHPUnit incorpora la suite Integration, con tablas efímeras sin migraciones permanentes.

## [Sin publicar] — Etapa 1.2a

### Añadido

- Registro oficial de Core, Auth, Users, Catalog, Sales, Cash, Tickets, Reports, Audit, Notifications, Devices, Settings y System, sin lógica de negocio.
- Matriz de dependencias y reglas documentadas; pruebas arquitectónicas de imports y ciclos.
- Pruebas de proveedores, recursos, migraciones y contrato de DI aislado en tests de Core.
- Plantillas mínimas del generador oficial y descubrimiento de pruebas modulares.

### Cambiado

- Larastan incluye los módulos y conserva nivel 5; se elimina una entrada scripts duplicada.
- El entorno portátil permite al generador invocar Composer sin instalación global.

Formato basado en [Keep a Changelog](https://keepachangelog.com/es-ES/1.1.0/) y versionado semántico.

## [0.1.0] - 2026-11-11

### Añadido

- Base Laravel 13 para PHP 8.3, sin funcionalidades de negocio.
- Entorno Compose con FPM, nginx y SQL Server persistente; drivers ODBC/SQL fijados.
- Conexión SQL configurable para desarrollo y Azure SQL.
- laravel-modules, Livewire y layout con Tailwind CDN versionado.
- PHPUnit, Pint, Larastan y documentación de arquitectura, hosting y operación.

### Cambiado

- Entorno local principal sustituido por Windows nativo y SQL Express por instrucción del usuario.
- PHP portátil con drivers SQL habilitados y lanzadores de preparación/ejecución con checksums.
- Conexión real verificada por autenticación Windows; base fresapos vacía y comprobaciones de calidad correctas.
- Docker conservado como referencia histórica; su validación no es requisito para el entorno local elegido.

### Pendiente de validación del alcance original

- Construcción y ejecución Docker, salud de contenedores y conexión real SQL, por ausencia de Docker en el equipo de implementación.
- Capacidades de hosting y conectividad Azure.
