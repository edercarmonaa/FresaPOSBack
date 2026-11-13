<?php

namespace Modules\Catalog\Application\Ports;

use Modules\Catalog\Contracts\CatalogPage;
use Modules\Catalog\Contracts\ImageReference;
use Modules\Catalog\Contracts\ProductQuery;
use Modules\Catalog\Contracts\ProductSnapshot;
use Modules\Catalog\Contracts\SaleProductSnapshot;

interface ProductRepository
{
    public function find(string $id, bool $withDeleted = false, bool $lock = false): ProductSnapshot;

    public function create(array $data): ProductSnapshot;

    public function update(string $id, array $data): ProductSnapshot;

    public function delete(string $id): void;

    public function restore(string $id): ProductSnapshot;

    public function search(ProductQuery $query): CatalogPage;

    public function forSale(string $id): SaleProductSnapshot;

    /** Incluye productos eliminados lógicamente. Se consulta bajo el lock de coordinación. */
    public function referencesImage(ImageReference $reference): bool;
}
