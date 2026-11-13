<?php

namespace Modules\Sales\Contracts;

interface CancellationApproval
{
    /** Resolver un código vigente autorizado; devuelve la descripción histórica. */
    public function reason(string $actorId, string $saleId, string $code): string;
}
