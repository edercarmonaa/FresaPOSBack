<?php

namespace Modules\Catalog\Contracts;

interface CatalogReader
{
    public function forSale(string $actorId, string $productId): SaleProductSnapshot;
}
