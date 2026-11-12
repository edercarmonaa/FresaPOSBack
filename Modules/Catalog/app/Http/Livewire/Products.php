<?php

namespace Modules\Catalog\Http\Livewire;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Modules\Catalog\Contracts\ProductSnapshot;
use Modules\Catalog\Http\CatalogAccess;
use Modules\Catalog\Http\CatalogFilters;
use Modules\Catalog\Http\CatalogPresentation;
use Modules\Catalog\Http\CatalogValidation;

class Products extends CatalogScreen
{
    public string $categoryId = '';

    public string $price = '';

    public string $categoryFilter = '';

    public string $categorySearch = '';

    public string $deleted = 'without';

    public string $sort = 'name';

    public string $direction = 'asc';

    #[Locked]
    public ?string $originalCategoryId = null;

    protected function kind(): string
    {
        return 'products';
    }

    private function requireAction(string $permission): string
    {
        $actor = $this->actor();
        app(CatalogAccess::class)->requireAny([$permission]);

        return $actor;
    }

    public function create(): void
    {
        $this->requireAction('catalog.products.create');
        $this->resetErrorBag();
        $this->editingId = $this->originalCategoryId = null;
        $this->name = $this->description = $this->categoryId = $this->price = $this->status = '';
        $this->isActive = true;
        $this->formOpen = true;
    }

    public function edit(string $id): void
    {
        $record = $this->catalog()->product($this->requireAction('catalog.products.update'), $id);
        $this->resetErrorBag();
        $this->editingId = $record->id;
        $this->originalCategoryId = $record->categoryId;
        $this->name = $record->name;
        $this->description = $record->description ?? '';
        $this->categoryId = $record->categoryId;
        $this->price = $record->price;
        $this->isActive = $record->isActive;
        $this->formOpen = true;
    }

    public function save(): void
    {
        $actor = $this->requireAction($this->editingId === null ? 'catalog.products.create' : 'catalog.products.update');
        $data = $this->validate(['name' => ['required', 'string', 'max:150'], 'description' => ['nullable', 'string', 'max:2000'],
            'categoryId' => ['required', 'ulid'], 'price' => ['required', 'string'], 'isActive' => ['boolean']], CatalogValidation::messages());
        if ($this->attempt(function () use ($actor, $data): void {
            $values = ['name' => $data['name'], 'description' => $data['description'], 'category_id' => $data['categoryId'], 'price' => $data['price']];
            if ($this->editingId === null) {
                $values['is_active'] = $data['isActive'];
            } else {
                $current = $this->catalog()->product($actor, $this->editingId);
                if ($current->isActive !== $this->isActive) {
                    app(CatalogAccess::class)->requireAny(['catalog.products.deactivate']);
                    $values['is_active'] = $data['isActive'];
                }
            }
            $values = CatalogValidation::data($values, true, $this->editingId);
            if ($this->editingId === null) {
                $this->catalog()->createProduct($actor, $values);
            } else {
                $this->catalog()->updateProduct($actor, $this->editingId, $values);
            }
        })) {
            $this->closeForm();
            $this->status = 'Producto guardado.';
        }
    }

    public function toggle(string $id): void
    {
        $actor = $this->requireAction('catalog.products.deactivate');
        if ($this->attempt(function () use ($actor, $id): void {
            $record = $this->catalog()->product($actor, $id);
            $this->catalog()->setProductActive($actor, $id, ! $record->isActive);
        })) {
            $this->status = 'Estado de producto actualizado.';
        }
    }

    public function remove(string $id): void
    {
        $actor = $this->requireAction('catalog.products.deactivate');
        if ($this->attempt(fn () => $this->catalog()->deleteProduct($actor, $id))) {
            $this->status = 'Producto enviado a la papelera.';
        }
    }

    public function restore(string $id): void
    {
        $actor = $this->requireAction('catalog.products.restore');
        if ($this->attempt(fn () => $this->catalog()->restoreProduct($actor, $id))) {
            $this->status = 'Producto restaurado y activo.';
        }
    }

    public function render(): View
    {
        $actor = $this->actor();
        if ($this->deleted !== 'without') {
            app(CatalogAccess::class)->trash(true);
        }
        $page = $this->catalog()->products($actor, CatalogFilters::query([...$this->filterData(), 'category_id' => $this->categoryFilter ?: null,
            'deleted' => $this->deleted, 'sort' => $this->sort, 'direction' => $this->direction], true));
        $presentation = new CatalogPresentation($this->catalog(), $actor);
        $items = array_map(fn (ProductSnapshot $product) => $presentation->product($product, request()), $page->items);
        $choiceQuery = ['search' => $this->categorySearch, 'per_page' => 100];
        $choices = $this->catalog()->categories($actor, CatalogFilters::query($choiceQuery, false));
        $activeChoices = $this->catalog()->categories($actor, CatalogFilters::query([...$choiceQuery, 'is_active' => true], false));
        $originalCategory = $this->originalCategoryId === null ? null : $this->catalog()->category($actor, $this->originalCategoryId, true);

        return view('catalog::livewire-products', ['items' => $items, 'pagination' => CatalogPresentation::meta($page)['pagination'],
            'categories' => $choices->items, 'activeCategories' => $activeChoices->items, 'originalCategory' => $originalCategory]);
    }
}
