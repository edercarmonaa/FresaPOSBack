<?php

namespace Modules\Sales\Infrastructure;

use Modules\Sales\Contracts\LineRemovalApproval;
use Modules\Sales\Domain\SalesConflict;

final class UnavailableLineRemoval implements LineRemovalApproval
{
    public function requireApproved(string $actorId, string $saleId, string $itemId, string $reasonCode): void
    {
        throw new SalesConflict('La eliminación requiere el catálogo autorizado de motivos y su validación funcional.');
    }
}
