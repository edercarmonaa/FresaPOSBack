<?php

namespace Modules\Catalog\Infrastructure\Persistence;

use Modules\Catalog\Application\Ports\CategoryRepository;
use Modules\Catalog\Contracts\CatalogPage;
use Modules\Catalog\Contracts\CategoryQuery;
use Modules\Catalog\Contracts\CategorySnapshot;
use Modules\Catalog\Infrastructure\Models\Category;

final class EloquentCategoryRepository implements CategoryRepository
{
    public function find(string $id, bool $withDeleted = false, bool $lock = false): CategorySnapshot
    {
        $query = Category::query();
        if ($withDeleted) {
            $query->withTrashed();
        }
        if ($lock) {
            CatalogQueries::requireTransaction();
            $query->lockForUpdate();
        }

        return $this->snapshot($query->findOrFail($id));
    }

    public function create(array $data): CategorySnapshot
    {
        CatalogQueries::requireTransaction();

        return $this->snapshot(Category::query()->create($data)->refresh());
    }

    public function update(string $id, array $data): CategorySnapshot
    {
        CatalogQueries::requireTransaction();
        $model = Category::query()->findOrFail($id);
        $model->fill($data)->save();

        return $this->snapshot($model->refresh());
    }

    public function delete(string $id): void
    {
        CatalogQueries::requireTransaction();
        Category::query()->findOrFail($id)->delete();
    }

    public function search(CategoryQuery $query): CatalogPage
    {
        $builder = Category::query();
        CatalogQueries::deleted($builder, $query->deleted);
        CatalogQueries::names($builder, $query->name);
        if ($query->isActive !== null) {
            $builder->where('is_active', $query->isActive);
        }
        $page = $builder->orderBy('display_order')->orderBy('name')->orderBy('id')->paginate($query->perPage, ['*'], 'page', $query->page);

        return new CatalogPage($page->getCollection()->map(fn (Category $category) => $this->snapshot($category))->all(), $page->total(), $query->page, $query->perPage);
    }

    private function snapshot(Category $model): CategorySnapshot
    {
        return new CategorySnapshot($model->id, $model->name, $model->description, $model->is_active, $model->display_order,
            $model->created_at->toISOString(), $model->updated_at->toISOString(), $model->deleted_at?->toISOString());
    }
}
