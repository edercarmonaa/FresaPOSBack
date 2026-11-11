# Arquitectura modular — etapas 1.2 y 1.3a

Actualización 1.2b: Core incorpora el contrato real TransactionManager y su adaptador Laravel en Infrastructure/Persistence. No se modifican permisos entre módulos. [Persistencia](persistencia.md) documenta ULID nativo, transacciones y efectos posteriores al commit; [entorno-pruebas.md](entorno-pruebas.md) describe la suite SQL independiente. La visión y responsabilidades originales se mantienen con las extensiones indicadas.

FresaPOSBack es un monolito modular: una aplicación Laravel, un despliegue y una base relacional. Los módulos separan responsabilidades y propiedad del código; no son microservicios. PHP 8.3 y Laravel 13 ejecutan el sitio web, Livewire y la futura API Kotlin. La etapa 1.2 registra infraestructura modular; 1.3a añade administración de usuarios y autorización, sin implementar operaciones POS.

## Responsabilidades previstas

| Módulo | Responsabilidad y límite |
| --- | --- |
| Core | Contratos y primitivas transversales estables. No contiene reglas de ventas/caja ni depende de módulos funcionales. No es un contenedor de utilidades sin dueño. |
| Auth | Autenticación, sesiones y mecanismos de acceso futuros. No administra el ciclo de vida del perfil de usuario. |
| Users | Identidad administrativa, estados, roles, permisos, Policies y servicios de usuarios. No implementa mecanismos de login, tokens ni sesiones. |
| Catalog | Catálogo comercial y su información descriptiva. No registra ventas ni movimientos de caja. |
| Sales | Casos de uso y reglas de ventas futuras. No modifica datos internos de Cash o Catalog. |
| Cash | Operación y control de caja futuros. No asume propiedad de las ventas. |
| Tickets | Generación y representación de comprobantes a partir de información pública. No recalcula ni confirma operaciones financieras. |
| Reports | Lectura y presentación de información consolidada. No modifica el estado de los módulos fuente. |
| Audit | Registro y consulta de evidencias de acciones. No controla la confirmación financiera. |
| Notifications | Entrega de avisos por canales futuros. Sus fallos no cambian operaciones confirmadas. |
| Devices | Registro y relación de dispositivos e integración futura con periféricos. No controla reglas de cobro. |
| Settings | Configuración funcional de la aplicación. No almacena secretos del despliegue ni sustituye el entorno. |
| System | Operación técnica, diagnóstico y mantenimiento del sistema. No sustituye Core ni concentra reglas funcionales. |

Estas responsabilidades son límites arquitectónicos, no funcionalidades implementadas. Todos los módulos están habilitados. Users incorpora modelos y una migración incremental de seguridad en 1.3a; los demás no tienen modelos ni migraciones funcionales. Core aporta el endpoint técnico ping de 1.2c. No hay controladores de negocio.

## Dependencias

La matriz ejecutable es `config/module-dependencies.php`. Core tiene lista vacía; los otros 12 módulos solo tienen permitido depender de Core. Es una **lista de permisos**, no una declaración de dependencias obligatorias, orden de carga ni `requires` del paquete. Actualmente no hay referencias productivas cruzadas. No se inventan relaciones Sales/Cash/Users antes de existir contratos reales.

Una nueva relación funcional se incorpora explícitamente a la matriz y a esta documentación, se justifica en revisión y debe superar la detección de ciclos. Tanto la matriz permitida como el grafo observado deben ser acíclicos; tampoco se permiten dependencias hacia sí mismo en la matriz.

Reglas obligatorias:

1. Core no depende de módulos funcionales; ningún módulo participa en un ciclo.
2. La comunicación entre módulos solo utiliza contratos públicos (`Contracts`), servicios de aplicación públicos (`Application/Services`) o eventos públicos (`Contracts/Events`). Esas carpetas se crean al añadir su primer archivo real.
3. Los detalles de Domain, Infrastructure, Http, modelos y repositorios concretos de otro módulo son privados. Un servicio público expone entradas/salidas explícitas y no entrega modelos internos mutables para que otro módulo los modifique.
4. No hay llamadas HTTP internas entre módulos. Se usa el contenedor para servicios/contratos o el mecanismo de eventos dentro del proceso. HTTP queda para límites externos reales.
5. Los controladores adaptan entradas, invocan un caso de uso y adaptan respuestas. No orquestan operaciones financieras complejas ni escriben en modelos de otro módulo.
6. Los eventos secundarios y sus consumidores (tickets, notificaciones, auditoría, etc.) no revierten operaciones financieras ya confirmadas. Sus errores deben tratarse como fallos secundarios recuperables; no se introduce esa implementación ni transacciones en 1.2a.

No usar referencias dinámicas por string, alias globales, resolución arbitraria del contenedor o consultas SQL como atajos para ocultar una dependencia prohibida.

## Estructura y capas

Cada módulo tiene esta base generada con el comando oficial y stubs locales mínimos:

```text
Modules/Name/
  module.json
  composer.json
  app/Providers/NameServiceProvider.php
  app/Providers/RouteServiceProvider.php
  config/config.php
  routes/web.php                 # sin endpoints
  database/migrations/           # sin migraciones
  resources/views/               # sin vistas de ejemplo
  tests/Feature/                 # disponible para pruebas reales
```

`NameServiceProvider` hereda el proveedor del paquete: registra configuración, namespaces de vistas/recursos y el proveedor de rutas. El paquete añade los directorios de migraciones al resolver el migrador; no conecta ni ejecuta migraciones al arrancar. Cada composer modular declara su namespace productivo y su autoload de pruebas. PHPUnit descubre las pruebas bajo Modules y Larastan analiza sus archivos productivos.

Las capas se crean **solo con contenido real** bajo `app/`:

| Capa | Contenido y dirección |
| --- | --- |
| Domain | Reglas e invariantes propias del módulo y eventos internos. No conoce HTTP, persistencia ni adaptadores concretos. |
| Application | Casos de uso y coordinación de contratos del dominio. Los servicios deliberadamente públicos van en Application/Services. |
| Infrastructure | Implementaciones de persistencia, clientes externos y adaptadores de contratos. Depende de abstracciones internas, no a la inversa. |
| Http | Controladores, validación de transporte y adaptación de respuestas. Delega en Application. |

`Contracts` reúne interfaces, mensajes y eventos de integración públicos; no implementaciones. `Providers` es la composición técnica. Core tiene Infrastructure/Persistence, Contracts y Http con contenido real; Domain y Application se crearán cuando exista su primer archivo. Las direcciones entre capas quedan documentadas para cuando exista contenido; la prueba actual verifica límites entre módulos, no toda la arquitectura interna futura.

## Validación automática y límites

`composer test` ejecuta carga de los 13 módulos y proveedores, registro de configuración/vistas/migraciones, ausencia de controladores modulares de ejemplo y una prueba de contrato por constructor. El contrato y su binding viven exclusivamente en las pruebas de Core: un proveedor de prueba hereda el proveedor real y registra el binding, sin servicios ficticios en producción.

La prueba arquitectónica analiza las sentencias PHP `use`, incluidos aliases, imports agrupados, funciones y constantes, y nombres plenamente cualificados. Usa PHP-Parser ya instalado por las herramientas de desarrollo; no añade paquetes ni Deptrac. Ignora comentarios, capturas `use` de closures y archivos de tests al construir el grafo productivo. Incluye casos negativos de accesos privados, dependencia prohibida de Core, módulos desconocidos y ciclos directos/indirectos.

Límites pendientes de validación semántica: referencias construidas dinámicamente, llamadas HTTP cuyo destino se calcula, acceso indirecto por SQL, complejidad financiera y efectos de eventos secundarios. Ningún análisis de imports demuestra esos comportamientos. Se revisarán con pruebas de casos reales y revisión de código cuando exista negocio. Hoy no existen tales operaciones y no se declaran verificadas artificialmente.

## Ejecución y alcance

Se mantiene Windows nativo, PHP 8.3.36, SQL Express AUDITORIA con autenticación Windows y servidor de desarrollo en 127.0.0.1:8080. Livewire y la conexión SQL no se cambian. Requisitos y comandos: [entorno-desarrollo.md](entorno-desarrollo.md). Los archivos Docker son históricos.

La etapa 1.2b añade ULID nativo verificado, transacciones, base independiente de pruebas y perfiles de entorno. La etapa 1.2c añade los contratos API y su transporte en Http, sin cambiar las dependencias entre módulos. Los contratos públicos ApiResponder, ApiCode y ApiExceptionMappings se resuelven en Core; los detalles Http siguen siendo privados. La raíz compone las rutas de módulos habilitados bajo api/v1 y solo Core aporta ping. Véase [api.md](api.md) y el [informe consolidado](entrega-1.2c.md).

## Actualización 1.3a — Users

Users añade Contracts con UserAdministration y UserSnapshot inmutable; Domain con normalización, política de contraseña y conflicto; Application con administración, puerto interno y coordinación; Infrastructure/Persistence con modelos Eloquent/Spatie y adaptador SQL. Policies usa Gate nativo. No se exportan modelos internos ni hashes. El puerto de persistencia es interno y mantiene el uso deliberado de Eloquent; el contrato público devuelve DTOs. Core no recibió lógica de identidad y Auth conserva su estructura para la siguiente etapa.

El proveedor de Users registra DI, Policy, alias morph y mapeo seguro de conflictos a través de contratos de Core. La raíz configura el modelo del proveedor Eloquent y Spatie; no se introduce una dependencia de Core hacia Users ni un ciclo Auth/Users. La matriz de dependencias no cambia. El historial de claves y el aprovisionamiento viven en Application, fuera de la superficie pública Application/Services.

No se añaden rutas administrativas. Las escrituras autorizadas usan el contrato transaccional existente y un lock SQL común para evitar carreras al conservar el último administrador. Véanse [seguridad y matriz](seguridad-permisos.md) y [entrega 1.3a](entrega-1.3a.md), que documentan límites de escritura directa, estados, contraseñas y responsabilidades futuras de autenticación.
## Actualización 1.3b — Auth

Auth depende de Core y de los contratos públicos IdentityAccess, UserSnapshot y UserAccessChanged de Users. Users sigue dependiendo solo de Core: no importa servicios ni modelos de Auth. La raíz compone el proveedor nativo y Sanctum; el trait oficial de tokens se integra en User. No se exportan modelos privados entre módulos ni se duplica la matriz de permisos.

Auth contiene Application (autenticación, registro de accesos y PIN), Domain (errores), Infrastructure/Models (tokens y metadatos), Http (controladores, middleware, Livewire y saneamiento de errores sensibles), Providers, recursos, idiomas y migración real. Las operaciones se coordinan con TransactionManager de Core; los cambios de estado de Users notifican después del commit para revocar accesos. Core conserva contratos y catálogo compartidos, sin lógica de identidad. Véase [autenticación](autenticacion.md).
