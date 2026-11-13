<?php

namespace Modules\Catalog\Application\Services;

use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Modules\Catalog\Application\CatalogInput;
use Modules\Catalog\Application\Ports\CategoryRepository;
use Modules\Catalog\Application\Ports\ProductRepository;
use Modules\Catalog\Contracts\CatalogAdministration;
use Modules\Catalog\Contracts\CatalogPage;
use Modules\Catalog\Contracts\CatalogReader;
use Modules\Catalog\Contracts\CategoryQuery;
use Modules\Catalog\Contracts\CategorySnapshot;
use Modules\Catalog\Contracts\ProductQuery;
use Modules\Catalog\Contracts\ProductSnapshot;
use Modules\Catalog\Contracts\SaleProductSnapshot;
use Modules\Catalog\Domain\CatalogConflict;
use Modules\Catalog\Domain\CatalogText;
use Modules\Catalog\Policies\CatalogPolicy;
use Modules\Core\Contracts\SecurityEvent;
use Modules\Core\Contracts\TransactionManager;
use Modules\Users\Contracts\IdentityAccess;

final class ManageCatalog implements CatalogAdministration, CatalogReader
{
    public function __construct(
        private readonly TransactionManager $transactions,
        private readonly IdentityAccess $identities,
        private readonly CatalogPolicy $policy,
        private readonly CategoryRepository $categoryRepository,
        private readonly ProductRepository $productRepository,
    ) {}

    public function createCategory(string $actorId, array $data): CategorySnapshot
    {
        return $this->write($actorId, 'catalog.categories.manage', function () use ($actorId, $data): CategorySnapshot {
            $values = CatalogInput::category($data, true);
            $record = $this->categoryRepository->create($values);
            $this->audit('category.created', $actorId, $record->id, array_keys($values));

            return $record;
        });
    }

    public function updateCategory(string $actorId, string $id, array $data): CategorySnapshot
    {
        return $this->write($actorId, 'catalog.categories.manage', function () use ($actorId, $id, $data): CategorySnapshot {
            $before = $this->categoryRepository->find($this->id($id), lock: true);
            $values = CatalogInput::category($data, false);
            $record = $this->categoryRepository->update($before->id, $values);
            $this->audit('category.updated', $actorId, $record->id, array_keys($values));

            return $record;
        });
    }

    public function setCategoryActive(string $actorId, string $id, bool $active): CategorySnapshot
    {
        return $this->write($actorId, 'catalog.categories.manage', function () use ($actorId, $id, $active): CategorySnapshot {
            $before = $this->categoryRepository->find($this->id($id), lock: true);
            $record = $this->categoryRepository->update($before->id, ['is_active' => $active]);
            $this->audit($active ? 'category.activated' : 'category.deactivated', $actorId, $record->id, ['is_active']);

            return $record;
        });
    }

    public function deleteCategory(string $actorId, string $id): void
    {
        $this->write($actorId, 'catalog.categories.manage', function () use ($actorId, $id): void {
            $record = $this->categoryRepository->find($this->id($id), lock: true);
            $this->categoryRepository->delete($record->id);
            $this->audit('category.deleted', $actorId, $record->id, ['deleted_at']);
        });
    }

    public function category(string $actorId, string $id, bool $withDeleted = false): CategorySnapshot
    {
        $this->authorize($actorId, ['catalog.products.view', 'catalog.categories.manage']);

        return $this->categoryRepository->find($this->id($id), $withDeleted);
    }

    public function categories(string $actorId, ?CategoryQuery $query = null): CatalogPage
    {
        $this->authorize($actorId, ['catalog.products.view', 'catalog.categories.manage']);

        return $this->categoryRepository->search($query ?? new CategoryQuery);
    }

    public function createProduct(string $actorId, array $data): ProductSnapshot
    {
        return $this->write($actorId, 'catalog.products.create', function () use ($actorId, $data): ProductSnapshot {
            $values = CatalogInput::product($data, true);
            $this->availableCategory($values['category_id']);
            $record = $this->productRepository->create($values);
            $this->audit('product.created', $actorId, $record->id, array_keys($values));

            return $record;
        });
    }

    public function updateProduct(string $actorId, string $id, array $data): ProductSnapshot
    {
        return $this->write($actorId, 'catalog.products.update', function () use ($actorId, $id, $data): ProductSnapshot {
            $before = $this->productRepository->find($this->id($id), lock: true);
            $values = CatalogInput::product($data, false);
            if (array_key_exists('is_active', $values)) {
                $this->authorize($actorId, ['catalog.products.deactivate']);
            }
            if ((isset($values['category_id']) && $values['category_id'] !== $before->categoryId) || ($values['is_active'] ?? false)) {
                $this->availableCategory($values['category_id'] ?? $before->categoryId);
            }
            $record = $this->productRepository->update($before->id, $values);
            $otherFields = array_diff(array_keys($values), ['image_disk', 'image_key']);
            if ($otherFields !== []) {
                $this->audit('product.updated', $actorId, $record->id, array_values($otherFields));
            }
            if ($before->image != $record->image) {
                $operation = $record->image === null ? 'image_removed' : ($before->image === null ? 'image_added' : 'image_replaced');
                $this->audit('product.'.$operation, $actorId, $record->id, ['image']);
            }
            $this->auditPrice($actorId, $before, $record);

            return $record;
        });
    }

    public function changePrice(string $actorId, string $id, mixed $price): ProductSnapshot
    {
        return $this->write($actorId, 'catalog.products.update', function () use ($actorId, $id, $price): ProductSnapshot {
            $before = $this->productRepository->find($this->id($id), lock: true);
            $record = $this->productRepository->update($before->id, CatalogInput::product(['price' => $price], false));
            $this->auditPrice($actorId, $before, $record);

            return $record;
        });
    }

    public function setProductActive(string $actorId, string $id, bool $active): ProductSnapshot
    {
        return $this->write($actorId, 'catalog.products.deactivate', function () use ($actorId, $id, $active): ProductSnapshot {
            $before = $this->productRepository->find($this->id($id), lock: true);
            if ($active) {
                $this->availableCategory($before->categoryId);
            }
            $record = $this->productRepository->update($before->id, ['is_active' => $active]);
            $this->audit($active ? 'product.activated' : 'product.deactivated', $actorId, $record->id, ['is_active']);

            return $record;
        });
    }

    public function deleteProduct(string $actorId, string $id): void
    {
        $this->write($actorId, 'catalog.products.deactivate', function () use ($actorId, $id): void {
            $record = $this->productRepository->find($this->id($id), lock: true);
            $this->productRepository->delete($record->id);
            $this->audit('product.deleted', $actorId, $record->id, ['deleted_at']);
        });
    }

    public function restoreProduct(string $actorId, string $id): ProductSnapshot
    {
        return $this->write($actorId, 'catalog.products.restore', function () use ($actorId, $id): ProductSnapshot {
            $before = $this->productRepository->find($this->id($id), withDeleted: true, lock: true);
            if ($before->deletedAt === null) {
                throw new CatalogConflict('El producto no está eliminado.');
            }
            $this->availableCategory($before->categoryId);
            $record = $this->productRepository->restore($before->id);
            $this->audit('product.restored', $actorId, $record->id, ['deleted_at', 'is_active']);

            return $record;
        });
    }

    public function product(string $actorId, string $id, bool $withDeleted = false): ProductSnapshot
    {
        $this->authorize($actorId, ['catalog.products.view']);

        return $this->productRepository->find($this->id($id), $withDeleted);
    }

    public function products(string $actorId, ?ProductQuery $query = null): CatalogPage
    {
        $this->authorize($actorId, ['catalog.products.view']);

        return $this->productRepository->search($query ?? new ProductQuery);
    }

    public function forSale(string $actorId, string $productId): SaleProductSnapshot
    {
        $this->authorize($actorId, ['catalog.products.view']);

        return $this->productRepository->forSale($this->id($productId));
    }

    private function availableCategory(string $id): void
    {
        $category = $this->categoryRepository->find($id, withDeleted: true, lock: true);
        if (! $category->isActive || $category->deletedAt !== null) {
            throw new CatalogConflict('La categoría debe existir, estar activa y no estar eliminada para asignar, activar o restaurar el producto.');
        }
    }

    /** @param list<string> $permissions */
    private function authorize(string $actorId, array $permissions): void
    {
        $actor = $this->identities->inspect($this->id($actorId));
        foreach ($permissions as $permission) {
            if ($actor && $this->policy->allows($actor, $permission)) {
                return;
            }
        }
        throw new AuthorizationException('No tienes permiso para esta operación del catálogo.');
    }

    private function id(string $id): string
    {
        try {
            return CatalogText::id($id);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['id' => $exception->getMessage()]);
        }
    }

    /** @template T
     * @param  Closure(): T  $operation
     * @return T
     */
    private function write(string $actorId, string $permission, Closure $operation): mixed
    {
        try {
            return $this->transactions->run(function () use ($actorId, $permission, $operation): mixed {
                // Misma coordinación de Users: cambios de permisos no intercalan una escritura autorizada.
                $this->identities->lock();
                $this->authorize($actorId, [$permission]);

                return $operation();
            });
        } catch (QueryException $exception) {
            if (in_array((int) ($exception->errorInfo[1] ?? 0), [2601, 2627], true)
                && str_contains(strtolower($exception->getSql()), 'catalog_categories')) {
                throw new CatalogConflict('Ya existe una categoría equivalente, incluso si fue eliminada lógicamente.');
            }
            throw $exception;
        }
    }

    private function audit(string $operation, string $actorId, string $recordId, array $fields): void
    {
        $fields = array_values(array_unique(array_map(fn (string $field) => in_array($field, ['image_disk', 'image_key'], true) ? 'image' : $field, $fields)));
        event(new SecurityEvent('catalog.'.$operation, actorId: $actorId, recordId: $recordId, changedFields: $fields));
    }

    private function auditPrice(string $actorId, ProductSnapshot $before, ProductSnapshot $after): void
    {
        if ($before->price !== $after->price) {
            event(new SecurityEvent('catalog.product.price_changed', actorId: $actorId, recordId: $after->id,
                changedFields: ['price'], priceBefore: $before->price, priceAfter: $after->price));
        }
    }
}
