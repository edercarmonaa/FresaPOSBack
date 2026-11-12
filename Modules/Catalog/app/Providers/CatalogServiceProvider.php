<?php

namespace Modules\Catalog\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\Catalog\Application\Ports\CategoryRepository;
use Modules\Catalog\Application\Ports\ProductRepository;
use Modules\Catalog\Application\Services\ManageCatalog;
use Modules\Catalog\Contracts\CatalogAdministration;
use Modules\Catalog\Contracts\CatalogReader;
use Modules\Catalog\Domain\CatalogConflict;
use Modules\Catalog\Infrastructure\Persistence\EloquentCategoryRepository;
use Modules\Catalog\Infrastructure\Persistence\EloquentProductRepository;
use Modules\Core\Contracts\ApiCode;
use Modules\Core\Contracts\ApiExceptionMappings;
use Nwidart\Modules\Support\ModuleServiceProvider;

class CatalogServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        parent::register();
        $this->app->bind(CategoryRepository::class, EloquentCategoryRepository::class);
        $this->app->bind(ProductRepository::class, EloquentProductRepository::class);
        $this->app->bind(CatalogAdministration::class, ManageCatalog::class);
        $this->app->bind(CatalogReader::class, ManageCatalog::class);
    }

    public function boot(): void
    {
        parent::boot();
        $this->app->make(ApiExceptionMappings::class)->register(CatalogConflict::class, ApiCode::CONFLICT);
    }

    protected string $name = 'Catalog';

    protected string $nameLower = 'catalog';

    /** @var array<class-string<ServiceProvider>> */
    protected array $providers = [
        RouteServiceProvider::class,
    ];
}
