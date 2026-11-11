# Contribuir

Usar documentación y explicaciones en español e identificadores de código en inglés. Mantener PHP 8.3.x y el alcance de cada entrega. No añadir negocio, dependencias, servicios o frontend compilado sin alcance aprobado.

Crear rama descriptiva, cambios pequeños y revisión mediante pull request. Antes de proponer cambios ejecutar dentro de app:

```sh
composer validate --strict
composer test
composer lint
composer analyse
```

Cambios de conexión o Docker requieren además construcción limpia, servicios sanos y consulta real SQL. Agregar pruebas que comprueben comportamiento significativo. Actualizar documentación/CHANGELOG y el lock al modificar dependencias; revisar requisitos PHP y avisos de seguridad. `composer install` reproduce el lock, `composer update` es una actualización deliberada.

Pint usa preset Laravel. Larastan nivel 5 se aumentará de manera deliberada; evitar supresiones globales. No versionar secretos, `.env`, vendor, datos SQL ni diagnósticos phpinfo. La licencia MIT cubre contribuciones; preservar avisos de dependencias. El titular legal del proyecto debe confirmar su denominación antes de publicar.
