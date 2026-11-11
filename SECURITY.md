# Seguridad

Desarrollo local actual: autenticación integrada Windows, sin sa ni contraseña SQL. La cuenta que ejecuta PHP determina sus permisos SQL. La infraestructura Docker previa no está activa; los requisitos de hosting y Azure siguen vigentes.

Versión 0.1.x: base en desarrollo, sin autorización para operar un POS productivo. Revisar actualizaciones de Laravel, PHP, drivers, imagen SQL y dependencias antes de cada despliegue.

Reportar vulnerabilidades por un canal privado del responsable del repositorio; el contacto privado y responsable de seguridad están **por confirmar**. No publicar pruebas con credenciales, datos de clientes ni vulnerabilidades explotables en issues públicos. Si no existe canal privado, solicitar al propietario que lo habilite antes de compartir detalles.

No guardar secretos en Git. `.env.example` solo incluye marcadores; APP_KEY se genera por entorno y se respalda. Ante exposición, revocar/rotar credenciales y claves afectadas, revisar accesos y evaluar invalidación de sesiones antes de limpiar el historial. Borrar un archivo no revoca una credencial.

Staging/producción: debug desactivado, HTTPS y cookies seguras, raíz web public, privilegios mínimos SQL, validación TLS en Azure y firewall limitado. Verificar drivers en PHP web y CLI y ODBC del sistema; sin esos requisitos no desplegar en el hosting compartido. `sa` y licencia SQL Developer solo locales.

Tailwind CDN introduce dependencia externa y todavía no existe CSP endurecida: ver docs/frontend.md y resolver la estrategia productiva antes del uso real. Las comprobaciones de dependencias no sustituyen revisión del despliegue ni gestión de incidentes.
