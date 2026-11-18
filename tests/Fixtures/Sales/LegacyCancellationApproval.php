<?php

namespace Tests\Fixtures\Sales;

use Modules\Sales\Contracts\CancellationApproval;

/** Solo fixtures de dominio anteriores al catálogo; nunca se registra en producción. */
final class LegacyCancellationApproval implements CancellationApproval
{
    public function reason(string $actorId, string $saleId, string $code): string
    {
        if (! app()->environment('testing')) {
            throw new \LogicException('Solo testing.');
        }

        return $code;
    }
}
