# Persistencia — etapa 1.2b

## Decisión ULID: reutilizar Laravel

Se utiliza `Illuminate\Database\Eloquent\Concerns\HasUlids` en los modelos que necesiten ese identificador y `$table->ulid('id')->primary()` en sus futuras migraciones. No se crea trait, generador ni modelo base propio: el framework ya genera claves string no incrementales, permite recuperarlas y serializarlas. Solo existe un modelo técnico en tests para comprobarlo; no se introduce una entidad de negocio.

Laravel 13.42.0 implementa Blueprint::ulid mediante char de longitud 26; **su gramática SQL Server traduce char a `nchar(26)`, no `char(26)`**. Se verificó realmente en `sys.columns`: tipo nchar y max_length **52 bytes**. HasUlids devuelve el valor en minúsculas en esta versión. Las pruebas verifican longitud 26, validez, unicidad de muestras, recuperación Eloquent y JSON con ID string sin conversión numérica. El consumidor debe tratarlo como identificador opaco; no se implementan rutas ni convenciones API en esta entrega.

En la tabla técnica vacía, `.primary()` crea una PK **CLUSTERED**, confirmado en sys.indexes. SQL Server hace esto por defecto si no hay otro clustered y no se pide uno nonclustered: [Microsoft](https://learn.microsoft.com/en-us/sql/relational-databases/indexes/create-clustered-indexes?view=sql-server-ver17). La clave es más ancha que char(26) o bigint; los índices no clustered pueden incorporar esa clave de localización y aumentar espacio, caché e I/O. El componente temporal del ULID puede favorecer localidad respecto a identificadores aleatorios, pero no garantiza orden global entre procesos, relojes ni ausencia de fragmentación. No se hizo benchmark de rendimiento.

Se conserva la gramática nativa. Si métricas reales justifican char ASCII, PK nonclustered u otra clave de agrupamiento, se diseñará esa migración de manera explícita por tabla. No se cambia globalmente el compilador SQL ni se añade una abstracción especulativa. Las FK ULID usan `foreignUlid`, conservando el mismo tipo nchar(26) de la PK.

## Convenciones aplicables

| Elemento | Convención |
| --- | --- |
| Tablas | Inglés, plural y snake_case. Definir el módulo propietario y evitar nombres ambiguos entre módulos. |
| Identificadores | `id` ULID cuando corresponda; FK como `parent_id`, del mismo tipo/longitud que la clave referenciada. |
| Integridad | FK reales en SQL, NOT NULL cuando corresponda, unicidad explícita e índices justificados por consultas. Indexar las FK que necesiten búsqueda; SQL Server no crea automáticamente ese índice por declarar la FK. |
| Importes | Decimal de precisión/escala declaradas. La prueba usa `decimal(19,4)` y cast `decimal:4`, incluyendo una magnitud que evidencia pérdida si se emplea float. Manejar entradas/resultados decimales como cadenas exactas; moneda y reglas de redondeo serán decisiones del negocio futuro. |
| Fechas | UTC. Laravel ya tiene app.timezone=UTC. Normalizar instantes a UTC antes de persistir y usar datetime2 con precisión explícita. Una columna datetime2 no guarda zona; el servidor SQL no realiza esa normalización por sí mismo. |
| Borrado | Sin cascadas de borrado sobre históricos financieros. La fixture usa `noActionOnDelete` y comprueba que SQL rechaza borrar un padre referenciado. |
| Concurrencia | No adoptar rowversion como estándar. Los bloqueos, restricciones y estrategia de concurrencia deben diseñarse según el caso de uso real. |

No se añadieron tablas de negocio ni migraciones permanentes. Estos criterios se aplicaron a fixtures técnicas descartables en la base independiente. No hay cambios de esquema sobre fresapos.

## Transacciones coordinadas por Application

Core expone `Modules\Core\Contracts\TransactionManager`, resuelto por el proveedor de Core a `Infrastructure\Persistence\LaravelTransactionManager`. El adaptador usa la conexión predeterminada de Laravel y `Connection::transaction` con **un intento**; no se fija una conexión local dentro del servicio. Así, el mismo contrato usa la conexión del entorno y la de integración aislada durante pruebas.

Los servicios de aplicación coordinan una unidad de trabajo con `run`. Los repositorios participan en esa conexión, sin abrir transacciones independientes ni confirmar por su cuenta. No cambiar la conexión predeterminada durante una operación. Esto es una transacción local de una base, no una transacción distribuida. Las invocaciones anidadas usan el soporte/savepoints de Laravel en la misma conexión; la confirmación definitiva corresponde al nivel exterior. Se comprobó que los callbacks anidados esperan esa confirmación.

Una excepción anterior al commit revierte la operación y se propaga. `afterCommit` solo acepta registro dentro de una transacción activa; fuera lanza LogicException. Los callbacks se descartan si ocurre rollback.

Los eventos públicos que deban entregarse tras confirmar pueden implementar el contrato nativo `ShouldDispatchAfterCommit`. Se verificaron entrega posterior y descarte por rollback con un evento de prueba, sin Event::fake ni colas. No existe un evento productivo ficticio.

**Fallo secundario después de confirmar:** no puede deshacer datos ya confirmados. La prueba de listener fallido verifica que el registro sigue en SQL y el nivel transaccional es cero. La excepción secundaria puede propagarse al llamador: no debe interpretarse como rollback ni provocar repetición ciega de toda la operación. El servicio responsable deberá registrar y recuperar el efecto secundario cuando exista un caso de uso. No se silencian todas las excepciones ni se promete entrega garantizada.

## Reintentos e idempotencia: estrategia documentada

No se implementan reintentos automáticos, tablas de idempotencia, outbox, workers ni colas. Ante deadlock o fallo transitorio, un reintento futuro deberá ser acotado, con espera/backoff, clasificación de errores y una operación idempotente. Si la respuesta del commit se pierde por un fallo de red, el resultado puede ser incierto: verificar el resultado antes de repetir.

El diseño futuro definirá alcance de clave de idempotencia, comparación de payload, resultado reutilizable y restricción única; la comprobación y operación deben coordinarse con la transacción adecuada. No ejecutar pagos externos, impresión o envíos irreversibles dentro de una función que pueda repetirse. Si se requiere entrega durable de efectos, diseñar su persistencia y recuperación en una entrega autorizada.

## Express y Azure SQL

Las fixtures ejecutan Schema Builder, migración reversible de prueba, ULID, decimal, datetime2, FK, índices y transacciones contra Express real. Se comprueba también la construcción del DSN Azure con el conector nativo, sin abrir conexión externa. Ambos perfiles usan el driver sqlsrv; el perfil Azure debe cifrar y validar el certificado.

**Pendiente:** ejecución real sobre Azure SQL, configuración de permisos, firewall/red, collation y rendimiento de índices/carga. El script de creación incluido es exclusivamente local y no aprovisiona Azure. Ver [base-datos-azure.md](base-datos-azure.md) y [hosting.md](hosting.md).

## Aplicación en 1.3a — Identidad y autorización

Users añade una migración incremental para users, roles, permissions, model_has_roles, model_has_permissions, role_has_permissions, user_password_histories y user_security_locks. Se aplicó en fresapos sin reconstruirla; Laravel creó también su tabla migrations. No hay usuarios ni contraseñas operativas sembradas. El informe [entrega-1.3a.md](entrega-1.3a.md) registra comandos y resultados reales.

Las identidades y FK usan ulid nativo, nchar(26). Las siete FK tienen NO ACTION, las pivots tienen PK compuesta e índices y el username único está normalizado también mediante CHECK SQL y collation BIN2. Se reservan los usernames eliminados; SoftDeletes conserva identidad y relaciones. La fila técnica de coordinación id=1 no es una identidad de negocio y usa tinyint. Timestamps continúan en UTC. La relación morph se restringe al alias users.user para poder mantener una FK real de model_id a users; no se habilitan equipos ni otros tipos de principal en Spatie.

El servicio de aplicación abre la transacción por el contrato de Core y el adaptador adquiere UPDLOCK/HOLDLOCK sobre user_security_locks. El lock se libera con commit/rollback y no depende del caché ni de la identidad del proceso. El repositorio no abre transacciones independientes. El historial bloquea la fila del usuario dentro de una transacción externa y conserva solo tres hashes recientes; no existe todavía un flujo público de restablecimiento.

La migración aborta antes de crear si ya existe una tabla de seguridad; no intenta sobrescribir esquemas ajenos. Su down solo permite bases cuyo nombre efectivo termine en _test. Una reversión operativa futura requiere un plan incremental y respaldo específicos, no desactivar esa salvaguarda. No se hicieron pruebas Azure; tipos, hints, FK y restricciones usan SQL Server, compartido con Azure SQL Database, cuya validación real sigue pendiente.
## Persistencia de autenticación — 1.3b

La migración incremental 2026_11_11_000002_create_auth_access_tables crea personal_access_tokens, auth_access_sessions y auth_employee_pins. Utiliza ULID nchar(26), FK reales sin cascada hacia usuarios, índices únicos filtrados para referencias opcionales y CHECK de canal/tipo. La eliminación de un token pone a NULL su referencia en el registro de acceso y conserva el metadato revocado. Los tokens se guardan como SHA-256; PIN como hash de contraseña. No se almacena el payload de sesión ni su identificador reutilizable: solo SHA-256.

La reversión de esta migración rechaza bases cuyo nombre no termina en _test; no se probó ni ejecutó rollback operativo. El esquema está aplicado en fresapos conservando roles/permisos y sin crear cuentas. Compatibilidad real verificada en SQL Server Express; Azure SQL sigue pendiente. [Detalle y resultados](entrega-1.3b.md).
