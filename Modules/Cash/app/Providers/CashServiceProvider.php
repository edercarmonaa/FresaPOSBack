<?php

namespace Modules\Cash\Providers;

use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use Modules\Cash\Application\Ports\CashStore;
use Modules\Cash\Application\Services\ManageCash;
use Modules\Cash\Console\ProvisionCash;
use Modules\Cash\Contracts\ActiveCashSession;
use Modules\Cash\Contracts\CashConflict;
use Modules\Cash\Contracts\CashOperations;
use Modules\Cash\Contracts\CashSessionUnavailable;
use Modules\Cash\Http\Livewire\CashDesk;
use Modules\Cash\Http\Middleware\CashAccess;
use Modules\Cash\Infrastructure\Persistence\EloquentCashStore;
use Modules\Core\Contracts\ApiCode;
use Modules\Core\Contracts\ApiExceptionMappings;
use Nwidart\Modules\Support\ModuleServiceProvider;

class CashServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        parent::register();
        $this->app->bind(CashStore::class, EloquentCashStore::class);
        $this->app->bind(CashOperations::class, ManageCash::class);
        $this->app->bind(ActiveCashSession::class, ManageCash::class);
    }

    public function boot(): void
    {
        parent::boot();
        $this->commands([ProvisionCash::class]);
        Livewire::component('cash.desk', CashDesk::class);
        Livewire::addPersistentMiddleware([CashAccess::class]);
        $this->app->make(ApiExceptionMappings::class)->register(CashConflict::class, ApiCode::CONFLICT);
        $this->app->make(ApiExceptionMappings::class)->register(CashSessionUnavailable::class, ApiCode::SERVICE_UNAVAILABLE);
    }

    protected string $name = 'Cash';

    protected string $nameLower = 'cash';

    /** @var array<class-string<ServiceProvider>> */
    protected array $providers = [
        RouteServiceProvider::class,
    ];
}
