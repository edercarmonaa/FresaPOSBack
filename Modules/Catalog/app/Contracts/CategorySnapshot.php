<?php

namespace Modules\Catalog\Contracts;

final readonly class CategorySnapshot
{
    public function __construct(
        public string $id,
        public string $name,
        public ?string $description,
        public bool $isActive,
        public int $displayOrder,
        public string $createdAt,
        public string $updatedAt,
        public ?string $deletedAt,
    ) {}
}
