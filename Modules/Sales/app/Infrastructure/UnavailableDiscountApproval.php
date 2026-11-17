<?php

namespace Modules\Sales\Infrastructure;

use Modules\Sales\Contracts\DiscountApproval;
use Modules\Sales\Domain\SalesDependencyUnavailable;

final class UnavailableDiscountApproval implements DiscountApproval
{
    public function requireApproved(string $actorId, string $saleId, string $subtotal, string $discount): void
    {
        throw new SalesDependencyUnavailable('La autorización de descuentos todavía no está disponible.');
    }
}
