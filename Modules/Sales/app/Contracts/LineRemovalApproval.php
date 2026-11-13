<?php

namespace Modules\Sales\Contracts;

interface LineRemovalApproval
{
    /** Debe validar el código contra el catálogo autorizado y el actor real; nunca aceptar texto libre. */
    public function requireApproved(string $actorId, string $saleId, string $itemId, string $reasonCode): void;
}
