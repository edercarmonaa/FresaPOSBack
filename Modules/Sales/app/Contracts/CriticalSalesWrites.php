<?php

namespace Modules\Sales\Contracts;

use Closure;

/** Adaptador futuro durable: misma transacción, actor/operación/clave, fingerprint,
 * exclusión concurrente, rechazo de reutilización incompatible y replay autorizado.
 * Nunca usar cache, memoria de proceso ni una bandera de configuración en producción.
 */
interface CriticalSalesWrites
{
    /** @param array<string, mixed> $payload
     * @param  Closure(): SaleSnapshot  $operation
     */
    public function run(string $actorId, string $scope, string $key, array $payload, Closure $operation): SaleSnapshot;
}
