<?php

namespace Tests\Fixtures\Sales;

use Modules\Sales\Contracts\LineRemovalApproval;
use Modules\Sales\Domain\SalesConflict;

final class TestRemovalApproval implements LineRemovalApproval
{
    public function requireApproved(string $actorId, string $saleId, string $itemId, string $reasonCode): void
    {
        if ($reasonCode !== 'TEST_APPROVED') {
            throw new SalesConflict('Motivo de prueba no aprobado.');
        }
    }
}
