<?php

namespace Modules\Catalog\Http\Livewire;

use Closure;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Modules\Catalog\Contracts\CatalogAdministration;
use Modules\Catalog\Domain\CatalogConflict;
use Modules\Catalog\Http\CatalogAccess;
use Modules\Catalog\Http\CatalogValidation;

abstract class CatalogScreen extends Component
{
    public string $search = '';

    public string $stateFilter = '';

    public int $perPage = 25;

    public int $page = 1;

    public string $status = '';

    public bool $formOpen = false;

    #[Locked]
    public ?string $editingId = null;

    public string $name = '';

    public string $description = '';

    public bool $isActive = true;

    abstract protected function kind(): string;

    protected function actor(): string
    {
        return app(CatalogAccess::class)->web($this->kind());
    }

    protected function catalog(): CatalogAdministration
    {
        return app(CatalogAdministration::class);
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'stateFilter', 'perPage', 'categoryFilter', 'deleted', 'sort', 'direction'], true)) {
            $this->page = 1;
        }
    }

    public function gotoPage(int $page): void
    {
        $this->actor();
        validator(['page' => $page], ['page' => ['integer', 'min:1', 'max:1000000']], CatalogValidation::messages())->validate();
        $this->page = $page;
    }

    public function closeForm(): void
    {
        $this->actor();
        $this->formOpen = false;
        $this->editingId = null;
        $this->resetErrorBag();
    }

    protected function filterData(): array
    {
        validator(['state' => $this->stateFilter], ['state' => ['in:,active,inactive']], CatalogValidation::messages())->validate();

        return ['search' => $this->search, 'is_active' => $this->stateFilter === '' ? null : $this->stateFilter === 'active',
            'page' => $this->page, 'per_page' => $this->perPage];
    }

    protected function attempt(Closure $operation): bool
    {
        $this->resetErrorBag();
        $this->status = '';
        try {
            $operation();

            return true;
        } catch (CatalogConflict $exception) {
            $this->addError('operation', $exception->getMessage());

            return false;
        }
    }
}
