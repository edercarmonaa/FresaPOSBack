<?php

namespace Modules\Catalog\Contracts;

interface CatalogAdministration
{
    public function createCategory(string $actorId, array $data): CategorySnapshot;

    public function updateCategory(string $actorId, string $id, array $data): CategorySnapshot;

    public function setCategoryActive(string $actorId, string $id, bool $active): CategorySnapshot;

    public function deleteCategory(string $actorId, string $id): void;

    public function category(string $actorId, string $id, bool $withDeleted = false): CategorySnapshot;

    public function categories(string $actorId, ?CategoryQuery $query = null): CatalogPage;

    public function createProduct(string $actorId, array $data): ProductSnapshot;

    public function updateProduct(string $actorId, string $id, array $data): ProductSnapshot;

    public function changePrice(string $actorId, string $id, mixed $price): ProductSnapshot;

    public function setProductActive(string $actorId, string $id, bool $active): ProductSnapshot;

    public function deleteProduct(string $actorId, string $id): void;

    public function restoreProduct(string $actorId, string $id): ProductSnapshot;

    public function product(string $actorId, string $id, bool $withDeleted = false): ProductSnapshot;

    public function products(string $actorId, ?ProductQuery $query = null): CatalogPage;
}
