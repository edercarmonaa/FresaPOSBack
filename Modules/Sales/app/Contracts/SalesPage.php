<?php

namespace Modules\Sales\Contracts;

final readonly class SalesPage
{
    /** @param list<SaleSummary|DiningTableSnapshot> $items */
    public function __construct(public array $items, public int $total, public int $page, public int $perPage) {}
}
