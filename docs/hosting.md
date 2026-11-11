# Hosting y despliegue

Único dato confirmado: PHP CLI 8.3.36 NTS, ionCube Loader 15.5.1 y OPcache. Eso no confirma las extensiones SQL ni la configuración del PHP web. ionCube no es una dependencia del proyecto.

## Riesgo crítico: controladores de Azure SQL

**Por verificar con el proveedor antes de aceptar el hosting:** PHP 8.3 del sitio carga `pdo_sqlsrv`, `sqlsrv` y Microsoft ODBC Driver 18 (`msodbcsql18`). Laravel utiliza PDO_SQLSRV; se verifican ambos drivers por requisito de esta entrega. Deben corresponder a versión PHP, arquitectura y NTS/TS del proceso. ODBC es una biblioteca del sistema: no se resuelve subiendo vendor ni activando ionCube.

Si hay terminal/SSH, ejecutar sin publicar resultados sensibles:

```sh
php -v
php --ini
php -m | grep -i sqlsrv
php -r 'var_export(extension_loaded("sqlsrv")); echo PHP_EOL; var_export(extension_loaded("pdo_sqlsrv")); echo PHP_EOL; var_export(PDO::getAvailableDrivers());'
odbcinst -q -d
odbcinst -q -d -n 'ODBC Driver 18 for SQL Server'
dpkg-query -W msodbcsql18
```

Los dos últimos comandos dependen del sistema y herramientas disponibles; solicitar al proveedor evidencia equivalente si no existen. Verificar salida con ambos módulos y `sqlsrv` entre los drivers PDO; confirmar versión ODBC instalada. Referencia: [requisitos Microsoft](https://learn.microsoft.com/en-us/sql/connect/php/system-requirements-for-the-php-sql-driver).

Sin SSH: usar la pantalla de extensiones/información PHP del panel o pedir al proveedor una comprobación por `phpinfo()` en **el mismo SAPI/pool del sitio**. Si solo se permite archivo temporal, restringirlo previamente a acceso administrativo/IP, crear `<?php phpinfo();`, verificar secciones sqlsrv/pdo_sqlsrv y configuración del sitio y **eliminarlo inmediatamente**. No dejar diagnóstico público ni versionarlo. `phpinfo()` puede revelar secretos; no adjuntar su salida completa a incidencias. ODBC se confirma con el proveedor y una conexión real, no solo con la lista de módulos.

Probar después `php artisan db:show --database=sqlsrv` desde el destino. Si no hay CLI, pedir al proveedor una ejecución equivalente con las credenciales protegidas; no crear un endpoint público de diagnóstico.

## Suposiciones pendientes

| Capacidad | Estado / acción necesaria |
| --- | --- |
| SSH y terminal | Por verificar; no requisito para subir artefacto construido. |
| Composer en servidor | Por verificar; alternativa CI/local descrita debajo. |
| Node/npm | Por verificar; esta entrega no los usa ni requiere. |
| Permiso de instalar extensiones / ODBC | Por verificar; habitualmente requiere al proveedor. |
| PHP web 8.3 y extensiones base | Por verificar: ctype, curl, DOM, fileinfo, filter, hash, intl, mbstring, OpenSSL, PCRE, PDO, session, SimpleXML, tokenizer, XML y zip; ejecutar check-platform-reqs. |
| DocumentRoot configurable a `public/` | Por verificar; nunca exponer raíz del proyecto. |
| Reglas de reescritura Laravel | Por verificar en Apache/nginx/panel; public/.htaccess incluido. |
| Salida de red, DNS, CA, IP fija y firewall Azure | Por verificar; consultar base-datos-azure.md. |
| Almacenamiento escribible, HTTPS y secretos | Por verificar para storage, bootstrap/cache, cookies y configuración. |
| Ejecución de comandos de despliegue y backups | Por verificar; acordar mecanismo del panel/proveedor. |

## Construir fuera del hosting

En CI o entorno local PHP **8.3.x** con extensiones equivalentes al destino y composer.lock versionado:

```sh
composer validate --strict
composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist
composer check-platform-reqs --no-dev
```

Construir en copia limpia o artefacto separado para no retirar herramientas de desarrollo del checkout. No usar `--ignore-platform-reqs` en CI de release: esa opción escondería incompatibilidades reales. No copiar vendor instalado para PHP 8.4/8.5. `config.platform` fija resolución, pero `check-platform-reqs` valida el runtime efectivo.

Subir aplicación y vendor construidos, lock, módulos/config necesarios y directorios storage/bootstrap/cache. Excluir `.git`, `.tools`, `.env*` de ejemplo si no se necesitan, pruebas, cachés locales, logs y Docker. Provisionar secretos propios en el destino, `APP_KEY` estable y respaldada, `APP_ENV=production` o staging, `APP_DEBUG=false`, HTTPS y `SESSION_SECURE_COOKIE=true`.

Apuntar web exclusivamente a public. Dar permisos mínimos de escritura a storage y bootstrap/cache. No subir cachés de configuración/rutas/vistas de otro entorno: podrían contener credenciales, rutas absolutas o APP_KEY local. Ya en destino ejecutar `php artisan optimize:clear`, `php artisan config:cache`, `php artisan view:cache` si se dispone de un mecanismo seguro. Si no hay terminal, el proveedor debe facilitar estas operaciones o desplegar sin caches precalculadas; nunca construir config:cache con secretos de producción en un artefacto público.

Comprobar HTTP raíz, assets de Livewire, logs, TLS y conexión Azure. No hay migraciones de negocio en esta entrega. Preparar rollback del artefacto y mantener el archivo de entorno del destino fuera de la distribución. Las sesiones/cache en archivos requieren almacenamiento persistente y una sola instancia o estrategia futura acordada.

## Alternativa si faltan drivers

Solicitar instalación/habilitación al proveedor, con evidencia para PHP web y CLI y prueba Azure. Si no permite ODBC/extensiones, **ese hosting no cumple**: mover a VPS administrado, contenedor o servicio con PHP 8.3 + ODBC 18 + drivers SQL controlables. No cambiar la base a MySQL ni desactivar TLS como solución. Selección de proveedor, recursos, costos y contratación requieren confirmación; no se efectúan en 1.1.
