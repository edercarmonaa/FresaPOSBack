# Registro de cambios

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
