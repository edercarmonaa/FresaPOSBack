<?php

namespace Modules\Sales\Infrastructure;

use Closure;
use Modules\Sales\Contracts\CriticalSalesWrites;
use Modules\Sales\Contracts\SaleSnapshot;
use Modules\Sales\Domain\SalesDependencyUnavailable;

final class UnavailableCriticalSalesWrites implements CriticalSalesWrites
{
    public function run(string $actorId, string $scope, string $key, array $payload, Closure $operation): SaleSnapshot
    {
        throw new SalesDependencyUnavailable('La operación requiere idempotencia durable y Cash funcional.');
    }
}
