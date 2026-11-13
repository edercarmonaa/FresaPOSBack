<?php

namespace Modules\Sales\Contracts;

final readonly class SaleSnapshot
{
    /** @param list<SaleItemSnapshot> $items */
    public function __construct(public SaleSummary $sale, public array $items) {}
}
