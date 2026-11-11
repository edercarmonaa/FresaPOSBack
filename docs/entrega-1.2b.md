# Informe de entrega — etapa 1.2b

## A. Estado inicial y línea base

Se retomó 1.2b después de la confirmación expresa del plan. La inspección verificó el prerrequisito 1.2a: 13 módulos habilitados, proveedores y reglas arquitectónicas presentes. No había persistencia de negocio, modelos productivos ni migraciones permanentes. No existía fresapos_test.

Versiones reales conservadas: PHP 8.3.36 NTS x64, Laravel 13.42.0, laravel-modules 13.3.0, Livewire 4.4.8, PHPUnit 12.5.43, Pint 1.32.2 y Larastan 3.14.0. SQL Express AUDITORIA y autenticación Windows existentes. La cuenta tenía permisos de creación; se creó solo la base independiente de pruebas.

Línea base ejecutada antes de editar: **10 pruebas / 215 aserciones**, Pint correcto y Larastan nivel 5 sin errores en **58 archivos**. La base local fresapos tenía 0 tablas. `git status --short` falló porque esta carpeta no contiene repositorio Git; no se inicializó ni hubo commits/pushes.

## B. Cambios

| Propósito | Archivos principales |
| --- | --- |
| Contrato transaccional | Modules/Core/app/Contracts/TransactionManager.php |
| Adaptador nativo Laravel | Modules/Core/app/Infrastructure/Persistence/LaravelTransactionManager.php |
| Registro de DI | Modules/Core/app/Providers/CoreServiceProvider.php |
| Conexión de integración independiente | config/database.php: se añade sqlsrv_testing, sin modificar el bloque sqlsrv existente. |
| Preparación de pruebas | scripts/setup-test-db.ps1/.cmd, init-test-db.php y create-test-database.sql como alternativa para el administrador. |
| Guardas y ciclo de vida | tests/Support/TestDatabaseGuard.php y SqlServerTestCase.php |
| Fixtures técnicas | tests/Fixtures/Persistence/{ProbeRecord,ProbeMigration,ProbeCommitted}.php |
| Pruebas nuevas | tests/Integration/{PersistenceTest,TransactionTest}.php y tests/Feature/{TestDatabaseGuardTest,SqlServerEnvironmentTest}.php |
| Entornos | .env.example, .env.testing.example, .env.staging.example y .env.production.example; .gitignore permite solo el ejemplo de pruebas. |
| Arranque local reproducible | scripts/setup-local.ps1 construye valores locales explícitos a partir del ejemplo con marcadores; conserva un .env existente. |
| Descubrimiento y documentación | phpunit.xml incorpora Integration; README, CHANGELOG y docs de arquitectura, módulos, entorno, persistencia y pruebas. |

**Sin dependencias nuevas ni cambios al lock.** Se verificó por SHA-256 que composer.lock y el .env operativo son idénticos al inicio. No se cambió autenticación, host, base ni credenciales locales. El archivo .env.testing generado es privado e ignorado. Las nuevas carpetas de Core tienen contenido real; se mantiene la matriz acíclica de 1.2a.

## C. Persistencia, transacciones y entornos

**ULID:** se reutilizan HasUlids y Blueprint::ulid. No se añade abstracción propia. La columna SQL real es **nchar(26), 52 bytes**, no char(26), por la gramática del framework instalado. El test confirma PK CLUSTERED, generación/recuperación Eloquent y serialización del ID como string de 26 caracteres. Se documenta el costo de esa clave en índices; no se hicieron benchmarks ni optimizaciones especulativas.

**Convenciones:** tablas inglesas/plurales/snake_case, FK reales, unicidad y FK indexada, decimal fijo (fixture decimal(19,4)), fechas normalizadas a UTC, no cascada de borrado histórico y no rowversion estándar. Solo se aplican a pruebas técnicas de esta etapa; no hay tablas de negocio.

**Transacciones:** el servicio de aplicación puede inyectar TransactionManager. Su adaptador usa una única conexión del entorno, delega en Laravel con un intento, revierte ante excepción y registra callbacks durante una transacción activa. Se probaron callbacks anidados tras el commit exterior y los eventos nativos ShouldDispatchAfterCommit. Un fallo secundario puede propagarse, pero no revierte los datos confirmados. Los repositorios futuros no deben abrir unidades de trabajo independientes.

**Entornos:** desarrollo conserva Windows Authentication y TLS local; staging/producción tienen marcadores para Azure con bases y credenciales independientes, debug desactivado, Encrypt=yes y TrustServerCertificate=no. La conexión de integración utiliza solo TEST_DB_* y carece de fallback al entorno operativo. No se abrió conexión Azure.

**Pruebas SQL:** usan fresapos_test. Antes de cualquier DDL se valida el nombre configurado, el driver, el entorno y DB_NAME efectivo. Se excluyen otras conexiones en el contenedor Laravel del test. Las tablas ordinarias efímeras tienen nombres únicos por caso y se eliminan al finalizar: no se usan tablas #temp porque sus FK no se aplican en SQL Server. No existen migraciones permanentes.

## D. Pruebas ejecutadas y comparación

Comandos desde PowerShell en la raíz. Se usó scripts/local.cmd para invocar PHP/Composer y la cuenta Windows autorizada para las pruebas SQL, sin cambiar permisos ni servicios.

| Comando / comprobación | Resultado real |
| --- | --- |
| scripts/setup-test-db.cmd con instancia AUDITORIA | Base fresapos_test creada y verificada; fresapos no se modificó. |
| Repetición del preparador | Correcta; .env.testing conserva su hash, sin borrar datos ni reconstruir la base. |
| `composer test` | **26 pruebas / 296 aserciones correctas**, frente a 10/215 iniciales. |
| `composer lint` | **Pint passed**, después de aplicar formato. |
| `composer analyse` | **Sin errores, nivel 5, 61 archivos**, frente a 58 iniciales. |
| `composer validate --strict` | Correcto. |
| `composer check-platform-reqs` | Todos los requisitos pasan, incluidos ambos drivers SQL 5.13.3 y PHP 8.3.36. |
| Suite Integration con TEST_DB_DATABASE=fresapos y --stop-on-error | **Rechazo esperado, código 2**, antes de crear tablas. Log privado .tools/guard-fresapos.log. |
| Suite Integration con TEST_DB_DATABASE=fresapos_stage y --stop-on-error | **Rechazo esperado, código 2**, antes de crear tablas. Log privado .tools/guard-fresapos_stage.log. |
| Guardas unitarias | Rechazo antes de abrir PDO, nombres inválidos, motor incorrecto, host ausente y nombre efectivo inesperado. |
| Sintaxis PowerShell | setup-local.ps1 y setup-test-db.ps1 analizados sin errores. |
| Generador del perfil local | Fragmento real del script ejecutado en memoria: reemplaza marcadores correctamente; no escribe el .env. |
| Comparación SHA-256 de .env y composer.lock | Ambos sin cambios. |
| `php artisan db:show --database=sqlsrv` | Conexión local fresapos en AUDITORIA correcta; 0 tablas, como al inicio. |
| Consultas a sys.tables tras las pruebas | **0 tablas de usuario en fresapos y 0 en fresapos_test**. Limpieza completada. |
| HTTP real raíz, JS Livewire y /up | Los tres responden **200** en 127.0.0.1:8080. |

La integración verifica tipo físico e índice de ULID, cadena JSON, decimal exacto, UTC, unicidad, rechazo FK de huérfanos, rechazo de borrado con hijo, índice de FK, migración reversible, commit, rollback, callbacks posteriores, eventos y fallo secundario sin deshacer commit. No se emplearon simulaciones de SQL ni otro motor. El test DSN de Azure comprueba construcción de configuración, no conectividad real.

Los dos códigos 2 son fallos deliberados para demostrar la salvaguarda, no una suite positiva fallida. La ejecución positiva de 26 pruebas pasó sin fallos inesperados. El script SQL alternativo de creación **no se ejecutó**: la preparación PHP pudo crear la base. No se ejecutó una instalación nueva en otro equipo; se comprobaron la sintaxis y el render de configuración, conservando el entorno existente.

## E. Pendientes Azure SQL y cPanel

**Pendiente, no ejecutado:** conexión y pruebas reales contra Azure, por no disponer de credenciales/recursos autorizados. Verificar versión/compatibilidad, collation, permisos, DNS, CA, firewall e IP pública de salida del hosting. Los perfiles del repositorio no contienen credenciales ni autorización para acceder a Azure.

**Pendiente, no verificado:** PHP web 8.3 del cPanel con pdo_sqlsrv, sqlsrv y ODBC Driver 18 del sistema. La disponibilidad en CLI no demuestra disponibilidad en el pool web. Confirmar con el proveedor mediante sus herramientas de PHP/phpinfo restringido y evidencia de ODBC; no dejar diagnóstico público. Sin drivers/ODBC, el hosting no cumple y deberá acordarse una alternativa. Revisar docs/hosting.md para el procedimiento y construcción de vendor fuera del servidor si falta Composer.

La suite no prueba recuperación tras caída abrupta del proceso ni entrega durable de eventos; se documentan como límites. Reintentos e idempotencia quedan solo como estrategia, sin tablas ni implementación. La evaluación de rendimiento del clustered ULID necesita carga real futura.

## F. Listo para 1.2c

Quedan listos los 13 módulos, contrato transaccional reutilizable, ULID nativo verificado como string, convenciones de persistencia, perfiles documentados y pruebas de integración separadas con salvaguardas. Esto permite retomar la inspección y punto de control de 1.2c.

No se agregaron /api/v1, respuestas uniformes, autenticación, Sanctum, negocio, Node, Vite, Docker, Redis ni colas. No se altera el manejo web/Livewire de errores en esta etapa.

## G. Decisiones que requieren confirmación

No queda una decisión bloqueante para completar 1.2b. La decisión de conservar nchar(26) nativo y usar tablas efímeras ordinarias fue incluida en el plan confirmado. Antes de producción deberán confirmarse capacidades del hosting, credenciales/red de Azure y futuros criterios financieros específicos. Se detiene el trabajo aquí; 1.2c requiere su propio punto de control y autorización.
