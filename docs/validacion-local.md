# Validación del entorno nativo Windows

11 de noviembre de 2026. La instrucción posterior del usuario sustituye Docker por PHP local y SQL Server Express. Este informe actualiza el estado de `validacion.md`, que conserva los resultados históricos de la primera preparación.

## Configuración efectiva

- PHP 8.3.36 CLI NTS x64 portátil en `.tools/php`.
- Composer 2.9.2, dependencias del lock; no se ignoran requisitos de plataforma.
- SQLSRV y PDO_SQLSRV 5.13.3, DLL para PHP 8.3 NTS x64, obtenidas de la release oficial Microsoft y verificadas con SHA-256.
- Microsoft ODBC Driver 18 x64 previamente instalado.
- SQL Server Express 64-bit, versión 17.0.1145.4, instancia `AUDITORIA`.
- Conexión local por memoria compartida, autenticación Windows y base `fresapos`, sin contraseña SQL almacenada. `.env` privado generado y APP_KEY propia, sin publicar su valor.

## Comprobaciones reales

| Comando / comprobación | Resultado |
| --- | --- |
| `sqlcmd -S '.\AUDITORIA' -E -C` con consulta de propiedades | Express detectado y conexión de Windows correcta. |
| Consulta PDO `SELECT 1` contra master | Devuelve 1 con autenticación integrada. |
| `scripts\setup-local.cmd -SqlInstance '.\AUDITORIA'` | Correcto: dependencias comprobadas, configuración preparada, clave generada y base creada. |
| `local.cmd php artisan db:show --database=sqlsrv` | Correcto: base fresapos, instancia AUDITORIA, conexión abierta, 0 tablas. |
| Segunda ejecución `local.cmd php scripts/init-local-db.php` | Correcta; base existente conservada. |
| Repetición completa de setup | Correcta; hash del .env idéntico antes y después, sin regenerar clave ni sobrescribir configuración. |
| `local.cmd php -v` y `local.cmd composer --version` | PHP 8.3.36 y Composer 2.9.2; argumentos reenviados correctamente. |
| `local.cmd composer check-platform-reqs` | Todos los requisitos pasan, incluidos sqlsrv y pdo_sqlsrv; sin excepciones. |
| `local.cmd composer validate --strict` | Correcto. |
| `local.cmd composer test` | 3 pruebas, 14 aserciones; correctas. |
| `local.cmd composer lint` | Pint passed. |
| `local.cmd composer analyse` | Larastan nivel 5, 6 archivos analizados, sin errores. |
| HTTP real `GET /`, recurso JS Livewire y `GET /up` | Los tres responden 200 desde 127.0.0.1:8080. |

Servidor de desarrollo iniciado únicamente en loopback. En esta sesión se lanzó PHP en segundo plano, con PID en `.tools/local-server.pid` y logs `.tools/local-server.stdout.log` / `.stderr.log`. No se configuró autoarranque ni servicio Windows. Para detener ese proceso en esta sesión:

```powershell
$serverProcessId = [int](Get-Content .tools/local-server.pid)
Get-Process -Id $serverProcessId | Select-Object Id, Path
# Después de verificar que corresponde al PHP local de este proyecto:
Stop-Process -Id $serverProcessId
```

En siguientes sesiones usar `scripts\local.cmd serve` en una terminal y Ctrl+C para detenerlo. El puerto debe estar libre.

## Cambios

Se añadieron `setup-local.ps1/.cmd` y `local.ps1/.cmd`; se adaptaron `.env.example`, defaults SQL, README y documentación de entorno, arquitectura, base de datos, versiones y registro de cambios. La infraestructura Docker previa se conserva como referencia y no se usa. No se modificaron servicios SQL, cuentas, firewall ni otras bases. No se añadieron dependencias ni funcionalidades de negocio.

Pendiente únicamente fuera del entorno local: comprobar capacidades del hosting, conexión Azure, estrategia productiva de Tailwind CDN y despliegue real. El servidor integrado PHP es solo de desarrollo. Los resultados anteriores de falta de drivers quedan superados por esta instalación local comprobada.
