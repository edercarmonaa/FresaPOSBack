<?php

namespace Modules\Sales\Contracts;

final readonly class SaleItemSnapshot
{
    public function __construct(
        public string $id, public string $saleId, public string $productId,
        public string $name, public string $categoryId, public string $categoryName,
        public string $unitPrice, public int $quantity, public ?string $note,
        public string $lineTotal, public int $position, public ?string $removedAt,
        public ?string $removedBy, public ?string $removalReasonCode,
    ) {}
}
