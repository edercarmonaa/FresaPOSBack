<?php

namespace Modules\Sales\Contracts;

interface SaleCheckout
{
    public function confirm(string $actorId, string $saleId, array $payments, string $key, ?string $revision): SaleSnapshot;
}
