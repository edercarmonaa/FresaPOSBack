<?php

namespace Tests\Fixtures\Sales;

use Modules\Sales\Contracts\CancellationApproval;
use Modules\Sales\Domain\SalesConflict;

final class TestCancellationApproval implements CancellationApproval
{
    public function reason(string $actorId, string $saleId, string $code): string
    {
        if (! app()->environment('testing') || $code !== 'TEST_APPROVED') {
            throw new SalesConflict('Motivo no autorizado.');
        }

        return 'Motivo autorizado exclusivo de prueba';
    }
}
