<?php

namespace Modules\Catalog\Contracts;

use InvalidArgumentException;
use Modules\Catalog\Domain\CatalogText;

final readonly class ProductQuery
{
    public string $name;

    public ?string $categoryId;

    public function __construct(
        string $name = '',
        ?string $categoryId = null,
        public ?bool $isActive = null,
        public string $deleted = 'without',
        public string $sort = 'name',
        public string $direction = 'asc',
        public int $page = 1,
        public int $perPage = 20,
    ) {
        $this->name = CatalogText::normalize($name, 100, false);
        $this->categoryId = $categoryId === null ? null : CatalogText::id($categoryId);
        if (! in_array($deleted, ['without', 'with', 'only'], true) || ! in_array($sort, ['name', 'price'], true)
            || ! in_array($direction, ['asc', 'desc'], true) || $page < 1 || $page > 1000000 || $perPage < 1 || $perPage > 100
            || count(explode(' ', $this->name)) > 8) {
            throw new InvalidArgumentException('Filtros, orden o paginación de productos inválidos.');
        }
    }
}
