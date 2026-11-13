<?php

namespace Modules\Sales\Contracts;

interface QuantityLimits
{
    public function maximumFor(string $productId): int;
}
