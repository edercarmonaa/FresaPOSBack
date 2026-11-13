<?php

namespace Modules\Catalog\Providers;

use Illuminate\Support\ServiceProvider;
use Livewire\Features\SupportFileUploads\FilePreviewController;
use Livewire\Livewire;
use Modules\Catalog\Application\Ports\CategoryRepository;
use Modules\Catalog\Application\Ports\ImageProcessor;
use Modules\Catalog\Application\Ports\ProductImageFiles;
use Modules\Catalog\Application\Ports\ProductRepository;
use Modules\Catalog\Application\Services\ManageCatalog;
use Modules\Catalog\Console\ReconcileProductImages;
use Modules\Catalog\Contracts\CatalogAdministration;
use Modules\Catalog\Contracts\CatalogReader;
use Modules\Catalog\Domain\CatalogConflict;
use Modules\Catalog\Http\Livewire\Categories;
use Modules\Catalog\Http\Livewire\Products;
use Modules\Catalog\Http\Middleware\CatalogAdminAccess;
use Modules\Catalog\Http\Middleware\CatalogImageUploadAccess;
use Modules\Catalog\Infrastructure\Images\FilesystemProductImages;
use Modules\Catalog\Infrastructure\Images\GdImageProcessor;
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
        $this->app->bind(ImageProcessor::class, GdImageProcessor::class);
        $this->app->bind(ProductImageFiles::class, FilesystemProductImages::class);
        $this->app->bind(CategoryRepository::class, EloquentCategoryRepository::class);
        $this->app->bind(ProductRepository::class, EloquentProductRepository::class);
        $this->app->bind(CatalogAdministration::class, ManageCatalog::class);
        $this->app->bind(CatalogReader::class, ManageCatalog::class);
    }

    public function boot(): void
    {
        parent::boot();
        if ($this->app->runningInConsole()) {
            $this->commands([ReconcileProductImages::class]);
        }
        config([
            'livewire.temporary_file_upload.disk' => 'local',
            'livewire.temporary_file_upload.rules' => ['required', 'file', 'max:'.intdiv((int) config('catalog.images.max_bytes'), 1024), 'mimes:jpg,jpeg,png,webp'],
            'livewire.temporary_file_upload.preview_mimes' => ['jpg', 'jpeg', 'png', 'webp'],
            'livewire.temporary_file_upload.middleware' => ['auth.no-store', 'auth.access.web', CatalogImageUploadAccess::class, 'throttle:60,1'],
        ]);
        FilePreviewController::$middleware = ['web', 'auth.no-store', 'auth.access.web', CatalogImageUploadAccess::class];
        Livewire::component('catalog.categories', Categories::class);
        Livewire::component('catalog.products', Products::class);
        $this->app->make('router')->aliasMiddleware('catalog.admin', CatalogAdminAccess::class);
        Livewire::addPersistentMiddleware([CatalogAdminAccess::class]);
        $this->app->make(ApiExceptionMappings::class)->register(CatalogConflict::class, ApiCode::CONFLICT);
    }

    protected string $name = 'Catalog';

    protected string $nameLower = 'catalog';

    /** @var array<class-string<ServiceProvider>> */
    protected array $providers = [
        RouteServiceProvider::class,
    ];
}
