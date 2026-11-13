<?php

namespace Tests\Fixtures\Sales;

use Closure;
use LogicException;
use Modules\Sales\Contracts\CriticalSalesWrites;
use Modules\Sales\Contracts\SaleSnapshot;
use Modules\Sales\Domain\SalesConflict;

/** Fixture para probar el cableado HTTP. NO acredita idempotencia durable/concurrente. */
final class TestCriticalSalesWrites implements CriticalSalesWrites
{
    private array $results = [];

    public function run(string $actorId, string $scope, string $key, array $payload, Closure $operation): SaleSnapshot
    {
        if (! app()->environment('testing')) {
            throw new LogicException('Solo testing.');
        }
        $index = $actorId.'/'.$scope.'/'.$key;
        $fingerprint = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
        if (isset($this->results[$index])) {
            if ($this->results[$index][0] !== $fingerprint) {
                throw new SalesConflict('Clave incompatible.');
            }

            return $this->results[$index][1];
        }
        $result = $operation();
        $this->results[$index] = [$fingerprint, $result];

        return $result;
    }
}
