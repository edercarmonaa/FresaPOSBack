<?php

namespace Modules\Sales\Contracts;

/** No aplica descuentos: exige evidencia de una autorización anterior. */
interface DiscountApproval
{
    public function requireApproved(string $actorId, string $saleId, string $subtotal, string $discount): void;
}
