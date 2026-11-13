<?php

namespace Modules\Sales\Infrastructure;

use Modules\Sales\Contracts\QuantityLimits;

final class GlobalQuantityLimits implements QuantityLimits
{
    public function maximumFor(string $productId): int
    {
        return (int) config('sales.max_quantity');
    }
}
