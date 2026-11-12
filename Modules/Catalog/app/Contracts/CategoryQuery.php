<?php

namespace Modules\Catalog\Contracts;

use InvalidArgumentException;
use Modules\Catalog\Domain\CatalogText;

final readonly class CategoryQuery
{
    public string $name;

    public function __construct(
        string $name = '',
        public ?bool $isActive = null,
        public string $deleted = 'without',
        public int $page = 1,
        public int $perPage = 20,
    ) {
        $this->name = CatalogText::normalize($name, 100, false);
        if (! in_array($deleted, ['without', 'with', 'only'], true) || $page < 1 || $page > 1000000 || $perPage < 1 || $perPage > 100
            || count(explode(' ', $this->name)) > 8) {
            throw new InvalidArgumentException('Filtros o paginación de categorías inválidos.');
        }
    }
}
