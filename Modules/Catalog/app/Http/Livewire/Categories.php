<?php

namespace Modules\Catalog\Http\Livewire;

use Illuminate\Contracts\View\View;
use Modules\Catalog\Http\CatalogFilters;
use Modules\Catalog\Http\CatalogPresentation;
use Modules\Catalog\Http\CatalogValidation;

class Categories extends CatalogScreen
{
    public int $displayOrder = 0;

    protected function kind(): string
    {
        return 'categories';
    }

    public function create(): void
    {
        $this->actor();
        $this->resetErrorBag();
        $this->editingId = null;
        $this->name = $this->description = $this->status = '';
        $this->displayOrder = 0;
        $this->isActive = true;
        $this->formOpen = true;
    }

    public function edit(string $id): void
    {
        $record = $this->catalog()->category($this->actor(), $id);
        $this->resetErrorBag();
        $this->editingId = $record->id;
        $this->name = $record->name;
        $this->description = $record->description ?? '';
        $this->isActive = $record->isActive;
        $this->displayOrder = $record->displayOrder;
        $this->formOpen = true;
    }

    public function save(): void
    {
        $actor = $this->actor();
        $data = $this->validate(['name' => ['required', 'string', 'max:150'], 'description' => ['nullable', 'string', 'max:1000'],
            'isActive' => ['boolean'], 'displayOrder' => ['integer', 'min:0', 'max:2147483647']], CatalogValidation::messages());
        if ($this->attempt(function () use ($actor, $data): void {
            $values = CatalogValidation::data(['name' => $data['name'], 'description' => $data['description'],
                'is_active' => $data['isActive'], 'display_order' => $data['displayOrder']], false, $this->editingId);
            if ($this->editingId === null) {
                $this->catalog()->createCategory($actor, $values);
            } else {
                $this->catalog()->updateCategory($actor, $this->editingId, $values);
            }
        })) {
            $this->closeForm();
            $this->status = 'Categoría guardada.';
        }
    }

    public function toggle(string $id): void
    {
        $actor = $this->actor();
        if ($this->attempt(function () use ($actor, $id): void {
            $record = $this->catalog()->category($actor, $id);
            $this->catalog()->setCategoryActive($actor, $id, ! $record->isActive);
        })) {
            $this->status = 'Estado de categoría actualizado.';
        }
    }

    public function remove(string $id): void
    {
        $actor = $this->actor();
        if ($this->attempt(fn () => $this->catalog()->deleteCategory($actor, $id))) {
            $this->status = 'Categoría enviada a eliminación lógica; sus referencias se conservan.';
        }
    }

    public function render(): View
    {
        $actor = $this->actor();
        $page = $this->catalog()->categories($actor, CatalogFilters::query($this->filterData(), false));

        return view('catalog::livewire-categories', ['result' => $page, 'pagination' => CatalogPresentation::meta($page)['pagination']]);
    }
}
