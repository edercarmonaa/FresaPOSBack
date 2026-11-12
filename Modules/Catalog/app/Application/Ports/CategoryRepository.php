<?php

namespace Modules\Catalog\Application\Ports;

use Modules\Catalog\Contracts\CatalogPage;
use Modules\Catalog\Contracts\CategoryQuery;
use Modules\Catalog\Contracts\CategorySnapshot;

interface CategoryRepository
{
    public function find(string $id, bool $withDeleted = false, bool $lock = false): CategorySnapshot;

    public function create(array $data): CategorySnapshot;

    public function update(string $id, array $data): CategorySnapshot;

    public function delete(string $id): void;

    public function search(CategoryQuery $query): CatalogPage;
}
