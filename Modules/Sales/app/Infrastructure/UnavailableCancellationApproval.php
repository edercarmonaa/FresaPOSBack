<?php

namespace Modules\Sales\Infrastructure;

use Modules\Sales\Contracts\CancellationApproval;
use Modules\Sales\Domain\SalesDependencyUnavailable;

final class UnavailableCancellationApproval implements CancellationApproval
{
    public function reason(string $actorId, string $saleId, string $code): string
    {
        throw new SalesDependencyUnavailable('El catálogo autorizado de motivos aún no está disponible.');
    }
}
