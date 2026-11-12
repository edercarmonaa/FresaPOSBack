<?php

namespace Modules\Catalog\Contracts;

// Información vigente inmutable; Sales será dueño de cantidad, importe y líneas históricas.
final readonly class SaleProductSnapshot
{
    public function __construct(
        public string $productId,
        public string $name,
        public string $categoryId,
        public string $categoryName,
        public string $price,
    ) {}
}
