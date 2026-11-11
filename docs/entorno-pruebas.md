# Pruebas de persistencia en SQL Server

## Preparación independiente

Windows x64, PHP 8.3.36 NTS con sqlsrv/pdo_sqlsrv y ODBC Driver 18 x64. Se reutiliza la instalación portátil y la autenticación Windows existente. No se usa Docker, SQLite, sa ni servicios nuevos.

Desde la raíz del repositorio, con la cuenta Windows autorizada:

```powershell
.\scripts\setup-test-db.cmd -SqlInstance '.\AUDITORIA'
.\scripts\local.cmd composer test
.\scripts\local.cmd composer lint
.\scripts\local.cmd composer analyse
```

El preparador crea `.env.testing` solo si no existe, a partir del ejemplo versionado y la instancia indicada. No copia ni modifica `.env`. El nombre predeterminado de integración es **fresapos_test**. La conexión `sqlsrv_testing` usa exclusivamente TEST_DB_*: no hereda DB_DATABASE, host ni credenciales locales o de despliegue. PHPUnit fuerza DB_CONNECTION=sqlsrv_testing y APP_ENV=testing. Laravel carga `.env.testing` sin afectar la instancia web de otro proceso.

El preparador valida el nombre y host local antes de conectarse. Usa master solo para consultar existencia y crear la base permitida si falta; después verifica la base efectiva de la conexión de pruebas. No elimina una base existente ni cambia sus usuarios/permisos. Si faltan permisos de creación, detenerse y entregar `scripts/create-test-database.sql` al administrador. Ese archivo se puede ejecutar así, una vez autorizado:

```powershell
sqlcmd -S '.\AUDITORIA' -E -C -d master -b -i .\scripts\create-test-database.sql
```

Repetir el preparador conserva `.env.testing`; sus parámetros no sobrescriben un archivo existente. No se ejecutan migraciones sobre fresapos ni hay operaciones de borrado general de bases.

## Salvaguardas que abortan la integración

Antes de crear tablas, cada test de integración exige:

1. APP_ENV=testing, conexión predeterminada sqlsrv_testing y ausencia de configuración Laravel cacheada.
2. Nombre explícito, válido y terminado en `_test`, distinto de fresapos, sin marcadores CHANGE_ME. No se admiten identificadores con SQL, espacios o partes de nombre múltiples.
3. Driver sqlsrv y TEST_DB_HOST explícito.
4. Consulta de solo lectura `SELECT DB_NAME()`; el resultado también debe ser seguro y coincidir con el nombre configurado.

En el contenedor Laravel de cada test de integración se retiran las otras conexiones de la configuración **en memoria**. Intentar resolver la conexión local sqlsrv falla; no se modifica el archivo de configuración ni la conexión de la aplicación web. Las comprobaciones se repiten antes de crear/eliminar los objetos propios. Sin preparación correcta los tests fallan, no pasan mediante un skip silencioso.

Las pruebas unitarias verifican nombres inseguros antes de abrir PDO, configuración de otro motor y discrepancia de base efectiva. Además se ejecutó una prueba negativa real de la suite con TEST_DB_DATABASE=fresapos y otra con fresapos_stage: ambas abortaron con código 2 antes del DDL.

Una configuración cacheada se rechaza; si necesitas retirarla, hacerlo deliberadamente fuera del test y verificar el entorno. No se limpia ni reescribe automáticamente la configuración del servidor productivo.

## Tablas efímeras y limpieza

Las tablas de cada test se llaman `probe_<sufijo aleatorio>_parents` y `probe_<sufijo aleatorio>_children`. Se crean mediante una clase Migration **solo en tests/Fixtures** y Schema Builder, no archivos en database/migrations. Se eliminan al terminar cada caso, hijo antes que padre, tras verificar de nuevo la base. No se usa un reset global ni se toca una tabla ajena al par creado por ese test.

Son tablas ordinarias de vida efímera en la base independiente; no tablas SQL `#temp`. Esto permite verificar FK reales, porque SQL Server no las aplica a tablas temporales de sesión: [Microsoft](https://learn.microsoft.com/en-us/sql/relational-databases/tables/create-foreign-key-relationships?view=sql-server-ver17).

La suite no usa DatabaseTransactions ni RefreshDatabase alrededor de los casos: debe observar commits reales y callbacks posteriores. Cada test transaccional controla su operación; tearDown revierte cualquier transacción pendiente y elimina exclusivamente sus tablas. Si el proceso termina abruptamente (corte eléctrico, kill), la limpieza PHP puede no ejecutarse. Revisar objetos probe huérfanos en fresapos_test y eliminarlos de forma selectiva después de verificar propiedad y ausencia de otra ejecución; no hay limpieza masiva automática.

## Cobertura

- Conexión real y tipo físico nchar(26), longitud 52 bytes, PK clustered.
- ULID nativo: creación, recuperación, serialización string y atributos Eloquent.
- Decimal(19,4), UTC, unicidad, FK real con rechazo de huérfanos y borrado sin cascada, índice de FK.
- Migración técnica reversible sin migraciones permanentes.
- Commit, rollback, callbacks anidados tras commit exterior y rechazo de callback fuera de transacción.
- Eventos nativos posteriores al commit; descarte en rollback y persistencia de datos pese a fallo secundario.
- Configuración DSN para Express/Azure sin conectar a Azure, y salvaguardas negativas.

Para ejecutar solo integración:

```powershell
.\scripts\local.cmd php vendor/phpunit/phpunit/phpunit --testsuite Integration
```

La suite completa mantiene las pruebas modulares, web y Livewire. El [informe 1.2b](entrega-1.2b.md) registra comandos, resultados y pendientes.

## Integración de usuarios y permisos — 1.3a

UserSecurityTest reutiliza SqlServerTestCase y TestDatabaseGuard. Después de validar testing, nombre _test, driver y DB_NAME efectivo, crea en fresapos_test el esquema real de seguridad de Users y lo retira al finalizar. Una tabla preexistente provoca rechazo, no un borrado automático. No se usa RefreshDatabase, migrate:fresh ni SQLite. Los hashes de contraseña de fixtures usan costo reducido solo dentro de la configuración de esa prueba; el entorno operativo no cambia.

Ejecutar las suites de forma secuencial en una base compartida: no iniciar dos procesos PHPUnit simultáneos contra fresapos_test, porque las nuevas tablas del esquema tienen nombres fijos. Las pruebas no dependen del orden de los casos, pero la paralelización requeriría una base _test propia por proceso. La prueba de concurrencia interna utiliza expresamente dos conexiones SQL sobre la misma base segura y un timeout acotado en el mismo batch que el SELECT bajo MARS; comprueba que el segundo escritor espera el lock.

La suite final de 1.3a pasó con 86 pruebas y 703 aserciones; fresapos_test quedó con cero tablas de usuario. El único test previo actualizado fue la expectativa de migraciones del registro modular: exige ahora exactamente la nueva migración de Users. El resto de pruebas y salvaguardas permanece intacto. Véase [informe 1.3a](entrega-1.3a.md).
## Integración de autenticación — 1.3b

AuthSqlServerTestCase reutiliza la salvaguarda SQL y crea los esquemas reales Users/Auth exclusivamente en fresapos_test. Rechaza tablas preexistentes, usa fixtures sin credenciales operativas y retira solo el esquema del caso al terminar. No ejecutar dos procesos de integración simultáneos sobre esta base. No hay SQLite, migrate:fresh ni db:wipe.

AuthenticationTest prueba cookies, CSRF, timeout, middleware, Sanctum, permisos, PIN y protocolo HTTP Livewire real. AdminBootstrapAuthenticationTest ejecuta el servicio y el comando reales con entrada de prueba controlada, verificando que las preguntas secretas están ocultas y no admiten fallback visible. La interacción física del terminal queda pendiente; no se creó ningún administrador operativo. [Informe con cifras finales](entrega-1.3b.md).
