# Entrega 1.3a — Usuarios, roles, permisos y fundamentos de seguridad

Fecha: 11 de noviembre de 2026. Implementación local completada. Sin avance a 1.3b.

## A. Línea base

Se leyeron los informes 1.2a, 1.2b y 1.2c, las convenciones de arquitectura/persistencia, los módulos, Core, Auth y Users, configuración de autenticación/SQL, rutas y pruebas. Se reutilizaron los 13 módulos, ULID nativo, TransactionManager, base aislada con TestDatabaseGuard y contrato de errores JSON. No había modelo User ni migraciones de identidad; config/auth.php apuntaba a App/Models/User, que no existía. No había catálogo previo de permisos ni casos operativos que ampliaran el alcance solicitado.

Versiones reales conservadas: PHP 8.3.36, Laravel 13.42.0, Livewire 4.4.8, laravel-modules 13.3.0, PHPUnit 12.5.43, Pint 1.32.2 y Larastan 3.14.0. Spatie y Sanctum no estaban instalados. Sanctum sigue sin instalarse. El directorio no contiene repositorio Git: git status --short devolvió ese error; no se inicializó Git ni se hicieron commits/pushes.

Todos los comandos se ejecutaron desde PowerShell en la raíz mediante `.\scripts\local.cmd`. La línea base **se ejecutó nuevamente antes de modificar código**, sin asumir los resultados anteriores:

| Comando | Línea base real |
| --- | --- |
| composer test | 55 pruebas, 558 aserciones; correctas. |
| composer lint | Pint passed. |
| composer analyse | Sin errores, nivel 5, 77 archivos. |
| composer validate --strict | Válido. |
| composer check-platform-reqs | Todos los requisitos satisfechos, incluidos sqlsrv y pdo_sqlsrv. |
| Consulta de solo lectura a fresapos | 0 tablas de usuario antes de la migración incremental. |

## B. Implementación

### Dependencias

Se instalaron **spatie/laravel-permission 8.5.0** y su dependencia **spatie/laravel-package-tools 1.93.3**. El manifest instalado declara PHP ^8.3 y componentes Illuminate ^12.0|^13.0, compatibles con el entorno. Se verificó además la [tabla oficial de compatibilidad](https://spatie.be/docs/laravel-permission/v8/prerequisites). No se actualizaron paquetes preexistentes ajenos a esta instalación. El lock cambió de forma deliberada; no se afirma que conserve el hash anterior.

El primer paso del lanzador de Windows interpretó la restricción ^8.0 como 8.0 e instaló temporalmente 8.0.0. Se corrigió composer.json a ^8.0 y Composer resolvió 8.5.0. La configuración final y el lock pasan validación estricta. La instalación no informó avisos de vulnerabilidad; esto no sustituye la revisión periódica de seguridad del despliegue.

### Componentes y archivos

| Responsabilidad | Ubicación |
| --- | --- |
| Servicio público y DTO inmutable | Modules/Users/app/Contracts/{UserAdministration,UserSnapshot}.php |
| Reglas independientes de HTTP | Modules/Users/app/Domain/{Username,PasswordStrength,SecurityConflict}.php |
| Administración de usuarios/roles | Modules/Users/app/Application/Services/ManageUsers.php |
| Puerto interno para consulta y coordinación | Modules/Users/app/Application/Ports/SecurityStore.php |
| Adaptador SQL/Eloquent | Modules/Users/app/Infrastructure/Persistence/EloquentSecurityStore.php |
| Modelos internos de persistencia | Modules/Users/app/Infrastructure/Persistence/Models/{User,Role,Permission,PasswordHistory}.php |
| Historial de claves y primer administrador | Modules/Users/app/Application/{PasswordHistoryService,BootstrapAdministrator}.php |
| Policy | Modules/Users/app/Policies/UserPolicy.php |
| Consola explícita sin credenciales por argumentos | Modules/Users/app/Console/ProvisionAdministrator.php |
| Composición, Policy y mapeo de conflicto | Modules/Users/app/Providers/UsersServiceProvider.php |
| Catálogo y matriz inicial | Modules/Users/config/security.php |
| Configuración del paquete | config/permission.php |
| Modelo del proveedor Eloquent nativo | config/auth.php |
| Migración incremental | Modules/Users/database/migrations/2026_11_11_000001_create_user_security_tables.php |
| Seeder idempotente | Modules/Users/database/seeders/SecuritySeeder.php |
| Autoload del seeder | Modules/Users/composer.json |
| Pruebas nuevas | tests/Integration/UserSecurityTest.php; tests/Feature/{PasswordStrengthTest,UserCompositionTest}.php |
| Documentación | Este informe, seguridad-permisos.md y actualizaciones puntuales de arquitectura/persistencia/pruebas, README y CHANGELOG. |

Core y Auth no recibieron lógica de usuarios ni clases duplicadas. No se modificó la matriz de dependencias: Users consume contratos de Core, que sigue sin depender de módulos funcionales. El proveedor de Users registra sus bindings y publica SecurityConflict como CONFLICT mediante el contrato de mapeos de Core; no importa su implementación Http.

Los servicios permiten crear, consultar/paginar, actualizar, activar/desactivar, eliminar lógicamente, asignar/retirar roles y consultar permisos efectivos. También permiten consultar roles, crear roles y administrar sus permisos con autorización. Las respuestas entre módulos son snapshots inmutables, sin modelos mutables ni hashes. El puerto de persistencia es interno y utiliza Eloquent deliberadamente; no se presenta como un dominio ORM independiente. Domain no conoce HTTP ni SQL.

No se añadieron controladores, formularios, endpoints CRUD, login, sesiones, tokens, PIN, bloqueo de intentos, auditoría avanzada ni operaciones POS. Las rutas de pruebas existen solo durante PHPUnit.

## C. Seguridad

### Roles, catálogo y Policies

Cuatro roles persistentes: admin/Administrador, supervisor/Supervisor, cashier/Cajero y waiter/Mesero. Hay **44 permisos**, con ocho capacidades de administración implementadas en esta etapa y el resto reservadas. La [matriz completa](seguridad-permisos.md) indica cada asignación y su estado. No hay permisos de inventario, ingredientes, recetas, mermas, devoluciones o reembolsos. users.delete corresponde exclusivamente a eliminación lógica; users.password.reset está reservado para una etapa posterior.

La autorización se realiza con UserPolicy, Gate y Spatie, por capacidades. No existe un bypass global por rol admin; se probó que retirar un permiso no protegido también lo deniega a un administrador. Un Gate::before exclusivamente denegatorio impide autorizaciones de usuarios inactivos o eliminados. Los servicios recargan el actor, por lo que no confían en un objeto antiguo con roles/estado anteriores. Los adaptadores futuros deben obtener actorId de la identidad autenticada, nunca del cuerpo de la solicitud.

Todos los cambios de seguridad usan TransactionManager de Core y un lock SQL común antes de leer/modificar estado. El adaptador usa UPDLOCK/HOLDLOCK sobre la fila técnica id=1, liberada al commit/rollback; no abre transacciones independientes. Se evita retirar, desactivar o eliminar al último administrador activo y no eliminado. El rol inicial admin conserva un mínimo explícito de ocho permisos de administración; el resto de su matriz puede modificarse. Las variantes de mayúsculas/espacios de nombres de rol se normalizan y no eluden la protección.

Las invariantes de administración se aplican mediante estos servicios, que constituyen la frontera admitida de escritura. SQL directo, cambios de pivots y APIs internas de Eloquent/Spatie realizados por un operador privilegiado no quedan autorizados por Policies; deben restringirse por despliegue y revisión. No se afirma haber instalado triggers universales que controlen toda modificación directa de SQL. Los modelos internos no se exportan a módulos funcionales.

### Usuarios y conservación

User extiende Authenticatable nativo con HasUlids, HasRoles y SoftDeletes. Campos: id, name, username, password, is_active, must_change_password, remember_token, created_at, updated_at y deleted_at. No exige email; su incorporación y recuperación posterior requerirán una migración incremental, sin cambiar la identidad por username.

Username se recorta y convierte a minúsculas. Solo admite ASCII a-z, dígitos, punto, guion y guion bajo, de 3 a 64 caracteres. SQL lo protege además con CHECK y UNIQUE. Se reservan también los nombres de usuarios eliminados para evitar suplantar identidades históricas. El DTO no contiene claves ni token de recuerdo; los modelos ocultan hashes. Los permisos efectivos de un usuario inactivo son una lista vacía, aunque conserve sus asignaciones para futura reactivación.

La eliminación lógica mantiene roles, historial e identidad para referencias futuras. forceDelete y forceDeleteQuietly se rechazan **antes** de los eventos, evitando que Spatie retire pivots antes de la denegación. Las FK sin cascada impiden además borrar físicamente las identidades referenciadas. Las futuras operaciones financieras y de auditoría deberán usar FK NO ACTION hacia users.

### Contraseñas y aprovisionamiento

Mínimo ocho caracteres Unicode, mayúscula, minúscula, número y puntuación/símbolo; sin controles ni UTF-8 inválido. Límite adicional de 72 bytes para evitar truncamiento bcrypt. Se utiliza Hash de Laravel con su configuración operativa, sin claves universales ni reducción de costo en producción. El costo reducido aparece solo en las fixtures de integración.

El historial conserva hashes seguros de las tres claves más recientes, incluida la actual. Rechaza su reutilización y elimina solo los hashes que salieron de esa ventana. El componente interno exige una transacción y bloqueo de fila. Se prueba mediante llamadas internas; no se expuso aún un flujo de cambio/restablecimiento administrativo. El cambio obligatorio posterior a reset y su enforcement en autenticación quedan para 1.3b.

Los seeders nunca crean usuarios. Se dejó `users:provision-admin`, interactivo, que solicita y confirma una contraseña oculta sin recibirla por argumentos, sin fallback visible y sin imprimirla ni registrarla. Rechaza ejecución no interactiva y solo crea la primera cuenta si nunca hubo usuarios, incluso eliminados. Se probó el servicio con credenciales ficticias exclusivamente en fresapos_test y la denegación no interactiva. **No se ejecutó interactivamente en fresapos ni se eligieron credenciales por el propietario.** El procedimiento está en seguridad-permisos.md.

## D. Base de datos

La migración se ejecutó primero contra SQL Server real en las pruebas. Posteriormente se aplicó de forma incremental en la base local fresapos mediante:

```powershell
.\scripts\local.cmd php artisan migrate --path=Modules/Users/database/migrations --no-interaction
.\scripts\local.cmd php artisan db:seed --class=Modules\Users\Database\Seeders\SecuritySeeder --no-interaction
```

Se crean **ocho tablas de seguridad**, más la tabla migrations de Laravel, que no existía:

| Tabla | Propósito y restricciones |
| --- | --- |
| users | PK ULID nchar(26), username nvarchar(64) BIN2 único y CHECK de normalización; estado indexado, hash, timestamps UTC y borrado lógico. |
| roles | PK ULID, name/guard_name únicos, etiqueta visible persistente. |
| permissions | PK ULID, name/guard_name únicos. |
| model_has_roles | PK compuesta, FK a roles y users; índice por modelo. |
| model_has_permissions | PK compuesta, FK a permissions y users; índice por modelo. |
| role_has_permissions | PK compuesta, FK reales e índice por permiso. |
| user_password_histories | PK ULID, FK de usuario indexada, hash oculto y fechas. |
| user_security_locks | Fila técnica id=1 para serializar cambios de seguridad; no es una entidad con identidad ULID. |

Las siete FK son NO ACTION, sin cascadas. Las relaciones de Spatie se limitan deliberadamente al modelo User, identificado por el alias morph estable users.user y un CHECK, permitiendo FK reales sobre model_id. No están habilitados teams ni otros tipos de principal. Las longitudes de nombres/guard están acotadas para mantener índices compatibles con SQL Server. Roles y permisos también usan HasUlids y el tipo nativo nchar(26).

La migración comprueba antes de crear que ninguna tabla de seguridad exista; si encuentra una, se detiene sin sobrescribirla y exige diseñar su integración incremental. El método down rechaza bases cuyo nombre efectivo no termine en _test, para prevenir rollback destructivo operativo; no se ejecutó en fresapos. No se utilizó migrate:fresh ni db:wipe.

El seeder crea permisos y roles faltantes mediante firstOrCreate. Solo asigna la matriz inicial a roles recién creados y conserva configuraciones existentes. Se ejecutó nuevamente en fresapos y se comparó la evidencia antes/después: mismos IDs, conteos y asignaciones. El estado operativo final comprobado es **9 tablas, 4 roles, 44 permisos, 0 usuarios y 0 historiales de contraseña**. No existen datos ficticios de negocio.

SQL Express AUDITORIA y Windows Authentication se conservan. Azure SQL comparte la semántica SQL Server utilizada, pero **no se conectó ni probó Azure**. Continúan pendientes credenciales autorizadas, TLS/CA, firewall, permisos, red y capacidades reales de cPanel: pdo_sqlsrv/sqlsrv y ODBC 18 en el PHP web. No se promete compatibilidad verificada por una conexión inexistente.

## E. Resultados reales

| Comando o comprobación final | Resultado real |
| --- | --- |
| `.\scripts\local.cmd composer test` | **86 pruebas, 703 aserciones correctas**, frente a 55/558 iniciales. |
| `.\scripts\local.cmd composer lint` | **Pint passed**. |
| `.\scripts\local.cmd composer analyse` | **Sin errores, nivel 5, 96 archivos**, frente a 77. |
| `.\scripts\local.cmd composer validate --strict` | **Válido**. |
| `.\scripts\local.cmd composer check-platform-reqs` | **Todos satisfechos**, incluidos PHP y ambos drivers SQL. |
| migrate:status con el path de Users | Migración registrada, batch 1, Ran. |
| route:list --except-vendor | Solo raíz y ping; no se incorporaron endpoints administrativos. |
| HTTP real GET /, /up, /api/v1/ping y JS local Livewire | **Los cuatro 200** en 127.0.0.1:8080. |
| Consulta posterior de fresapos_test | **0 tablas de usuario** tras limpieza de fixtures. |
| Hash de .env y config/database.php | Idénticos al inicio. |

Las 31 pruebas nuevas cubren reglas de claves ASCII/Unicode y controles, creación/consulta/actualización de usuarios, hashes y serialización, historial y rechazo de las tres últimas claves, normalización y restricciones SQL saltando la validación de aplicación, nombres reservados tras soft delete, activación/desactivación, asignación/retirada de roles, permisos efectivos, roles configurables, denegación de escalada, inactividad, último administrador, borrado físico, invariantes con ADMIN normalizado, seeding idempotente que conserva asignaciones y hashes, aprovisionamiento único y no interactivo, ULID físico, FK sin cascada, esquema existente, contratos/DI y errores JSON 403/409.

La prueba de concurrencia usa dos sesiones SQL reales: la primera mantiene el lock transaccional y la segunda recibe timeout SQL 1222. No se simula el servidor ni se usa SQLite. Se verifica también que el adaptador rechaza un lock fuera de una transacción. Esto comprueba el mecanismo de serialización; no constituye una prueba de carga ni un benchmark.

Las pruebas de seguridad reutilizan SqlServerTestCase y TestDatabaseGuard antes de cualquier DDL, restringiendo las conexiones al perfil testing. Crean el esquema real de Users solo en fresapos_test y lo retiran al terminar; rechazan una tabla preexistente en lugar de borrarla. No ejecutar dos suites simultáneas sobre esa misma base: los nombres de estas tablas de integración son fijos. Para paralelizar en el futuro se requieren bases _test independientes por proceso.

**Conservación de pruebas:** todas las pruebas anteriores siguen ejecutándose. Se modificó únicamente la expectativa de tests/Feature/ModuleRegistrationTest.php que exigía cero migraciones en todos los módulos: ahora exige exactamente la migración nueva en Users y cero en los demás. No se eliminó ni silenció esa validación. Los hashes del resto de las pruebas anteriores coinciden con la instantánea inicial.

**Problemas intermedios corregidos:** un filtro que contenía una barra vertical fue interpretado por el lanzador Windows; se sustituyó por filtros simples. La primera prueba del lock quedó esperando porque SET LOCK_TIMEOUT no se conservaba entre batches hermanos bajo MARS. Se corrigió ejecutando SET y SELECT en un único batch. Durante el diagnóstico se inició prematuramente otra suite sobre la misma base y sus 15 casos de seguridad rechazaron correctamente el esquema ya existente. Se detuvo únicamente el proceso de PHPUnit identificado, se liberó la transacción y se limpiaron sus propias fixtures tras comprobar la salvaguarda de fresapos_test. La repetición secuencial completa terminó en 86/703, sin fallos. No se utilizó la base operativa para esa limpieza.

**No ejecutado/pendiente:** Azure SQL y hosting reales, interacción de navegador con una administración inexistente, autenticación real y el comando operativo con credenciales del propietario. El transporte HTML/Livewire y su JavaScript sí se verificaron por HTTP y con las pruebas anteriores. La validación automática YAML/OpenAPI pendiente de 1.2c conserva su estado; no se añadió parser en esta entrega.

## F. Integridad

No se modificaron .env, la configuración SQL, Windows Authentication, servicios, permisos de Windows/SQL ni firewall. La comparación SHA-256 de .env conserva `A8ED9F5D179949F9179F4466618AD26721D62A9E2353920DC8CF7CC3E0C97A2B`; config/database.php conserva `46D9D47A67B8B917842FA96576A927CF390432B43841B0E9DC76457FAA7F0A3C`.

**fresapos sí recibió las migraciones incrementales y el seeding requeridos**, expresamente descritos arriba; no se presenta como una base sin cambios. La inspección inicial confirmó 0 tablas: no se eliminaron ni sobreescribieron tablas/usuarios/datos previos. Ahora hay únicamente el esquema de seguridad y su catálogo, sin cuentas operativas ni negocio. fresapos_test terminó vacía tras la suite. No se cambiaron web, vistas, Livewire, Core, CORS ni los contratos existentes de API.

No se instalaron Docker, Node, Vite, Sanctum, Redis, colas ni herramientas no usadas. No se hicieron commits ni pushes.

## G. Pendientes para 1.3b y 1.3c

- El propietario debe elegir sus credenciales y ejecutar el aprovisionamiento inicial si desea crear la primera cuenta; no se inventaron credenciales.
- Implementar autenticación web/API y decidir Sanctum, guard y emisión de tokens en su etapa. El proveedor Eloquent actual identifica el modelo; **el futuro flujo de autenticación debe filtrar estado activo y borrado**, además de aplicar cambio obligatorio. Las Policies actuales no sustituyen ese control de login.
- Desarrollar cambio y restablecimiento de contraseña autorizados, reutilizando el historial y forzando cambio tras reset administrativo; definir recuperación/email sin imponerlos ahora.
- PIN, intentos fallidos, sesiones, cambio rápido, revocación y auditoría avanzada en sus entregas correspondientes.
- Añadir interfaces/endpoints de administración con actor tomado del guard y las mismas Policies, sin acceso directo a modelos de otros módulos.
- Implementar y probar reglas operativas de los permisos reservados al crear cada módulo funcional. No inferir invariantes financieras de la mera existencia de un permiso.
- Confirmar compatibilidad real de Azure/cPanel y despliegue, privilegios mínimos de SQL y protección de logs, respaldos y gestión de secretos. No se modificó CORS.

## H. Conclusión

**La etapa 1.3a quedó completada y validada en el entorno local.** Usuarios, roles, permisos, servicios, Policies, migración y seeder funcionan con SQL Server y respetan los contratos modulares. Las verificaciones externas y el aprovisionamiento con credenciales elegidas por el propietario quedan explícitos; no bloquean la infraestructura local solicitada. El trabajo se detiene aquí, sin iniciar 1.3b.
