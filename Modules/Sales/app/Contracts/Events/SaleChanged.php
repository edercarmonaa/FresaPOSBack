<?php

namespace Modules\Sales\Contracts\Events;

final readonly class SaleChanged
{
    public function __construct(public string $saleId, public string $actorId, public string $operation) {}
}
