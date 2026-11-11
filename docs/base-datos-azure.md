# SQL Server Express local y Azure SQL

La etapa 1.2b conserva intacta la conexión local y añade **sqlsrv_testing**, exclusiva para integración. Usa TEST_DB_* independientes y una base terminada en _test; no tiene fallback a DB_*. Ver [persistencia](persistencia.md) para tipos ULID, convenciones, índices y transacciones, y [entorno de pruebas](entorno-pruebas.md) para las salvaguardas.

Cada destino tiene un .env privado o variables suministradas por el hosting. Los archivos .env.staging.example y .env.production.example no se cargan automáticamente: sirven de referencia para bases y credenciales **independientes**, con APP_DEBUG=false. .env.example mantiene marcadores para campos de despliegue y valores técnicos explícitos, sin dirección/base/credenciales reales. El setup local genera el perfil de desarrollo si falta el archivo; no sobrescribe el actual.

Windows Authentication: DB_USERNAME y DB_PASSWORD vacíos; la identidad del proceso PHP debe estar autorizada en SQL. Para Azure con autenticación SQL, se informan ambos valores, DNS del servidor y puerto 1433. Local permite TrustServerCertificate=yes para el certificado local; Azure exige Encrypt=yes y TrustServerCertificate=no. Configurar CA, DNS y firewall, no desactivar validación como solución. Un hosting Linux/cPanel no hereda tu cuenta Windows.

`config/database.php` usa conexión `sqlsrv`. Host, puerto, base, credenciales y TLS se parametrizan por entorno. No existe una URL de conexión alternativa que pueda sustituir silenciosamente esos valores.

| Perfil | Host y puerto | Credenciales | TLS |
| --- | --- | --- | --- |
| Windows local | Instancia nombrada, DB_PORT vacío | DB_USERNAME y DB_PASSWORD vacíos: autenticación Windows | Encrypt=yes y TrustServerCertificate=yes para certificado local |
| Azure | DNS del servidor, DB_PORT=1433 | Principal SQL propio y contraseña privada | Encrypt=yes, TrustServerCertificate=no, validación de CA y nombre DNS |

El ejemplo local usa la instancia SQLEXPRESS; este equipo tiene la instancia AUDITORIA. El setup recibe el nombre real como parámetro y lo escribe solo al crear un .env nuevo. La conexión actual está verificada por memoria compartida; no necesita abrir TCP ni activar SQL Browser. Una cuenta de servicio o del sandbox distinta no hereda los permisos SQL de tu sesión Windows.

La preparación crea únicamente la base vacía fresapos mediante `scripts/init-local-db.php`: consulta parametrizada en master y nombre validado antes de crear DB. Solo admite APP_ENV=local. Si ya existe, conserva sus tablas y datos. Se verificó la ejecución repetida. La cuenta Windows necesita permiso de creación o el administrador debe crear previamente la base y conceder acceso. No se cambian cuentas, permisos ni otras bases automáticamente. No hay migraciones de negocio en esta entrega.

Para autenticación SQL rellenar DB_USERNAME y DB_PASSWORD en el entorno privado. No usar sa como cuenta de aplicación ni reutilizar credenciales entre entornos. Azure requiere servidor/base y principal previamente aprovisionados, con permisos mínimos y una credencial de despliegue separada si posteriormente se necesitan migraciones.

Al cambiar entorno, editar .env y ejecutar `artisan optimize:clear`. Validación real: `scripts/local.cmd php artisan db:show --database=sqlsrv`. No corregir un fallo TLS en Azure activando TrustServerCertificate.

Azure requiere salida de red, DNS, CA y reloj correctos. Configurar firewall para la **IP pública de salida del hosting**, no la del navegador ni necesariamente la IP de entrada del sitio. Confirmar con el proveedor si es fija, compartida o múltiple. No habilitar acceso universal ni la excepción amplia de todos los servicios Azure por conveniencia.

Con política Redirect verificar los puertos adicionales de la [arquitectura de conectividad Azure SQL](https://learn.microsoft.com/en-us/azure/azure-sql/database/connectivity-architecture); Proxy y Private Endpoint tienen requisitos distintos. Un Private Endpoint necesita red privada y DNS apropiado desde el hosting. Los recursos y la conexión Azure quedan pendientes; no se crearon ni modificaron en esta entrega.
