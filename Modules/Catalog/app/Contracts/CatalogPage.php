<?php

namespace Modules\Catalog\Contracts;

final readonly class CatalogPage
{
    /** @param list<ProductSnapshot|CategorySnapshot> $items */
    public function __construct(public array $items, public int $total, public int $page, public int $perPage) {}
}
