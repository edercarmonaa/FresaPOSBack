# Seguridad y matriz inicial de permisos — 1.3a

La matriz corresponde a los cuatro roles persistidos por el seeder inicial de Fase 1. Los 44 identificadores representan capacidades; únicamente las ocho de administración de usuarios/roles indicadas como implementadas tienen servicios en esta entrega. Las restantes son reservas para etapas posteriores y no habilitan ventas, caja ni otras funcionalidades inexistentes.

Se comparó el catálogo solicitado con la documentación existente: no había un catálogo operativo previo ni casos de negocio implementados. Se conservan los límites de Fase 1 y no se registran inventario, ingredientes, recetas, mermas, devoluciones ni reembolsos. Se añadió users.delete porque esta entrega requiere eliminación lógica protegida. users.password.reset se reserva, sin restablecimiento administrativo implementado.

Los roles son admin (Administrador), supervisor (Supervisor), cashier (Cajero) y waiter (Mesero). El administrador recibe las 44 capacidades explícitas en el primer seeding; no existe un bypass de Gate por su nombre. Supervisor dispone de supervisión operativa y autorizaciones especiales; cajero de ventas/caja/tickets; mesero de consulta y consumos abiertos, sin cierre, caja ni descuentos. Reapertura de ventas, caja administrativa, notificaciones críticas y configuración quedan inicialmente solo en admin; sus reglas operativas se definirán al implementar cada caso, sin deducirlas de este permiso.

| Permiso | Admin | Supervisor | Cajero | Mesero | Implementación |
| --- | --- | --- | --- | --- | --- |
| audit.view | Sí | — | — | — | Etapa posterior |
| cash.administrative.execute | Sí | — | — | — | Etapa posterior |
| cash.close | Sí | Sí | Sí | — | Etapa posterior |
| cash.closings.view | Sí | Sí | Sí | — | Etapa posterior |
| cash.movements.create | Sí | Sí | Sí | — | Etapa posterior |
| cash.open | Sí | Sí | Sí | — | Etapa posterior |
| cash.view | Sí | Sí | Sí | — | Etapa posterior |
| catalog.categories.manage | Sí | — | — | — | Etapa posterior |
| catalog.products.create | Sí | — | — | — | Etapa posterior |
| catalog.products.deactivate | Sí | — | — | — | Etapa posterior |
| catalog.products.restore | Sí | — | — | — | Etapa posterior |
| catalog.products.update | Sí | — | — | — | Etapa posterior |
| catalog.products.view | Sí | Sí | Sí | Sí | Etapa posterior |
| devices.manage | Sí | — | — | — | Etapa posterior |
| notifications.critical.resolve | Sí | — | — | — | Etapa posterior |
| notifications.resolve | Sí | Sí | — | — | Etapa posterior |
| notifications.settings.manage | Sí | — | — | — | Etapa posterior |
| notifications.view | Sí | Sí | Sí | Sí | Etapa posterior |
| reports.export | Sí | — | — | — | Etapa posterior |
| reports.view | Sí | Sí | — | — | Etapa posterior |
| roles.permissions.manage | Sí | — | — | — | Servicio 1.3a |
| roles.view | Sí | — | — | — | Servicio 1.3a |
| sales.cancel | Sí | Sí | — | — | Etapa posterior |
| sales.close | Sí | Sí | Sí | — | Etapa posterior |
| sales.create | Sí | Sí | Sí | Sí | Etapa posterior |
| sales.discounts.apply | Sí | Sí | — | — | Etapa posterior |
| sales.open.update | Sí | Sí | Sí | Sí | Etapa posterior |
| sales.reopen | Sí | — | — | — | Etapa posterior |
| sales.special.authorize | Sí | Sí | — | — | Etapa posterior |
| sales.view | Sí | Sí | Sí | Sí | Etapa posterior |
| settings.update | Sí | — | — | — | Etapa posterior |
| settings.view | Sí | — | — | — | Etapa posterior |
| system.administrative.execute | Sí | — | — | — | Etapa posterior |
| system.view | Sí | — | — | — | Etapa posterior |
| tickets.print | Sí | Sí | Sí | — | Etapa posterior |
| tickets.settings.manage | Sí | — | — | — | Etapa posterior |
| tickets.view | Sí | Sí | Sí | Sí | Etapa posterior |
| users.create | Sí | — | — | — | Servicio 1.3a |
| users.delete | Sí | — | — | — | Servicio 1.3a |
| users.password.reset | Sí | — | — | — | Etapa posterior |
| users.roles.assign | Sí | — | — | — | Servicio 1.3a |
| users.status | Sí | — | — | — | Servicio 1.3a |
| users.update | Sí | — | — | — | Servicio 1.3a |
| users.view | Sí | — | — | — | Servicio 1.3a |
## Modificación y autorización

El catálogo inicial y la matriz están en Modules/Users/config/security.php. El estado administrable reside en SQL Server: cambiar el archivo no sobreescribe configuraciones persistidas. UserAdministration permite consultar roles, crear uno nuevo y sustituir su conjunto de permisos. El seeder solo asigna la matriz inicial a un rol recién creado y preserva los ya existentes; al incorporar un permiso nuevo se requiere una asignación explícita a los roles correspondientes. No hay wildcard ni permiso universal de admin.

Todas las operaciones sensibles se verifican en el servidor mediante UserPolicy y el Gate nativo integrado con Spatie. Actividad y eliminación lógica niegan cualquier autorización; el DTO de un usuario inactivo devuelve permisos efectivos vacíos. Las asignaciones persistidas se conservan para una futura reactivación. El guard canónico del catálogo es web, también para reutilizarlo después desde API/Android; la autenticación de esos clientes todavía no existe.

La protección del último administrador es una invariante adicional, no una autorización por rol: se conserva al menos un usuario activo, no eliminado y con el rol administrativo inicial. El rol admin debe mantener las ocho capacidades de administración indicadas en protected_admin_permissions. Las demás asignaciones son configurables. Cada escritura del servicio adquiere un lock SQL transaccional común antes de leer actor/estado y modificar datos; los repositorios no abren transacciones independientes.

No modificar los modelos internos, las tablas pivot o la configuración de permisos mediante SQL para saltarse los servicios. El acceso directo de un operador a la base queda fuera de las Policies de la aplicación y requiere privilegios mínimos y control operativo. Las futuras FK financieras/auditoría deberán apuntar a users sin cascada. No existe todavía auditoría avanzada ni revocación de sesiones.

## Preparación de autenticación

El actorId de un servicio es contexto de identidad confiable: un futuro controlador debe obtenerlo del guard autenticado, jamás del cuerpo de una petición. Mantener auth y las Policies del lado servidor; ocultar un botón no autoriza una operación. Aún no se exponen endpoints CRUD ni formularios administrativos.

config/auth.php utiliza el modelo Eloquent de Users en el proveedor nativo; no hay un User duplicado en App. En 1.3b el flujo de autenticación deberá rechazar is_active=false y deleted_at no nulo antes de emitir sesión/token y aplicar must_change_password. La denegación actual de Gate no sustituye esa verificación de autenticación. Sanctum, PIN, bloqueo por intentos y revocación quedan pendientes.

## Contraseñas y aprovisionamiento

Mínimo ocho caracteres Unicode; mayúscula, minúscula, número y puntuación/símbolo; sin caracteres de control. Límite adicional de 72 bytes para evitar truncamiento en bcrypt. Los hashes se generan con Hash de Laravel y se ocultan en serialización; nunca se acepta un hash como contraseña desde el servicio. user_password_histories conserva solo los hashes de las tres contraseñas más recientes, incluida la actual. Su componente interno exige una transacción externa y bloquea la fila del usuario; no expone todavía cambio ni restablecimiento al cliente.

El seeder nunca crea usuarios ni contraseñas. Tras migrar y sembrar roles, el propietario puede ejecutar desde una consola privada e interactiva:

```powershell
.\scripts\local.cmd php artisan users:provision-admin
```

Se solicitan nombre, username, contraseña y confirmación, sin recibir claves por argumentos ni registrarlas. Si la terminal no permite ocultar la entrada, no se usa una entrada visible como fallback. Sin interacción se rechaza. El comando solo puede crear el primer usuario cuando no existen cuentas, incluyendo eliminadas; nunca sobreescribe usuarios ni actúa como recuperación. Marca cambio obligatorio, cuyo flujo corresponde a 1.3b. No se ejecutó para crear una cuenta operativa durante esta entrega: el propietario debe elegir las credenciales.

Referencias oficiales consultadas: [compatibilidad de Spatie](https://spatie.be/docs/laravel-permission/v8/prerequisites) y [adaptación de identificadores](https://spatie.be/docs/laravel-permission/v8/advanced-usage/uuid). Se verificó también el manifest y la implementación instalada, además de pruebas reales con SQL Server.
## Actualización 1.3b

La autenticación Web/API ya está disponible: véase [autenticacion.md](autenticacion.md). Se conserva íntegra la matriz anterior de cuatro roles y 44 permisos. Auth usa los contratos de Users y el proveedor rechaza identidades inactivas/eliminadas; un cambio de estado revoca accesos tras commit. El panel mínimo requiere users.view y la entrada mediante PIN rechaza identidades con permisos users.* o roles.*. El comando users:provision-admin permanece manual y exige el catálogo protegido inicializado, sin crear credenciales predeterminadas.
