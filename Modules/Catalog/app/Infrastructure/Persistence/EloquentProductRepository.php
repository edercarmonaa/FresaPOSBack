<?php

namespace Modules\Catalog\Infrastructure\Persistence;

use Modules\Catalog\Application\Ports\ProductRepository;
use Modules\Catalog\Contracts\CatalogPage;
use Modules\Catalog\Contracts\ImageReference;
use Modules\Catalog\Contracts\ProductQuery;
use Modules\Catalog\Contracts\ProductSnapshot;
use Modules\Catalog\Contracts\SaleProductSnapshot;
use Modules\Catalog\Domain\CatalogConflict;
use Modules\Catalog\Infrastructure\Models\Product;

final class EloquentProductRepository implements ProductRepository
{
    public function find(string $id, bool $withDeleted = false, bool $lock = false): ProductSnapshot
    {
        $query = Product::query();
        if ($withDeleted) {
            $query->withTrashed();
        }
        if ($lock) {
            CatalogQueries::requireTransaction();
            $query->lockForUpdate();
        }

        return $this->snapshot($query->findOrFail($id));
    }

    public function create(array $data): ProductSnapshot
    {
        CatalogQueries::requireTransaction();

        return $this->snapshot(Product::query()->create($data)->refresh());
    }

    public function update(string $id, array $data): ProductSnapshot
    {
        CatalogQueries::requireTransaction();
        $model = Product::query()->findOrFail($id);
        $model->fill($data)->save();

        return $this->snapshot($model->refresh());
    }

    public function delete(string $id): void
    {
        CatalogQueries::requireTransaction();
        Product::query()->findOrFail($id)->delete();
    }

    public function restore(string $id): ProductSnapshot
    {
        CatalogQueries::requireTransaction();
        $model = Product::onlyTrashed()->findOrFail($id);
        $model->is_active = true;
        $model->restore();

        return $this->snapshot($model->refresh());
    }

    public function search(ProductQuery $query): CatalogPage
    {
        $builder = Product::query();
        CatalogQueries::deleted($builder, $query->deleted);
        CatalogQueries::names($builder, $query->name);
        if ($query->categoryId !== null) {
            $builder->where('category_id', $query->categoryId);
        }
        if ($query->isActive !== null) {
            $builder->where('is_active', $query->isActive);
        }
        $page = $builder->orderBy($query->sort, $query->direction)->orderBy('id')->paginate($query->perPage, ['*'], 'page', $query->page);

        return new CatalogPage($page->getCollection()->map(fn (Product $product) => $this->snapshot($product))->all(), $page->total(), $query->page, $query->perPage);
    }

    public function forSale(string $id): SaleProductSnapshot
    {
        $model = Product::query()->join('catalog_categories as category', 'category.id', '=', 'catalog_products.category_id')
            ->where('catalog_products.id', $id)->where('catalog_products.is_active', true)
            ->where('category.is_active', true)->whereNull('category.deleted_at')
            ->select(['catalog_products.*', 'category.name as category_name'])->first();
        if (! $model) {
            throw new CatalogConflict('El producto o su categoría no están disponibles para nuevas ventas.');
        }

        return new SaleProductSnapshot($model->id, $model->name, $model->category_id, $model->getAttribute('category_name'), $model->price);
    }

    private function snapshot(Product $model): ProductSnapshot
    {
        $image = $model->image_key === null ? null : new ImageReference($model->image_disk, $model->image_key);

        return new ProductSnapshot($model->id, $model->category_id, $model->name, $model->description, $model->price,
            $model->is_active, $image, $model->created_at->toISOString(), $model->updated_at->toISOString(), $model->deleted_at?->toISOString());
    }

    public function referencesImage(ImageReference $reference): bool
    {
        CatalogQueries::requireTransaction();

        return Product::withTrashed()->where('image_disk', $reference->disk)->where('image_key', $reference->key)->exists();
    }
}
