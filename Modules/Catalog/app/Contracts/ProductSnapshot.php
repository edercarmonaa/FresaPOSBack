<?php

namespace Modules\Catalog\Contracts;

final readonly class ProductSnapshot
{
    public function __construct(
        public string $id,
        public string $categoryId,
        public string $name,
        public ?string $description,
        public string $price,
        public bool $isActive,
        public ?ImageReference $image,
        public string $createdAt,
        public string $updatedAt,
        public ?string $deletedAt,
    ) {}
}
