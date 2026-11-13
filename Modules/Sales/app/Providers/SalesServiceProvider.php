<?php

namespace Modules\Sales\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\Cash\Contracts\ActiveCashSession;
use Modules\Core\Contracts\ApiCode;
use Modules\Core\Contracts\ApiExceptionMappings;
use Modules\Sales\Application\Ports\SalesStore;
use Modules\Sales\Application\Services\ManageSales;
use Modules\Sales\Contracts\CancellationApproval;
use Modules\Sales\Contracts\CriticalSalesWrites;
use Modules\Sales\Contracts\LineRemovalApproval;
use Modules\Sales\Contracts\QuantityLimits;
use Modules\Sales\Contracts\SalesOperations;
use Modules\Sales\Domain\CashUnavailable;
use Modules\Sales\Domain\SalesConflict;
use Modules\Sales\Domain\SalesDependencyUnavailable;
use Modules\Sales\Infrastructure\GlobalQuantityLimits;
use Modules\Sales\Infrastructure\Persistence\EloquentSalesStore;
use Modules\Sales\Infrastructure\UnavailableCancellationApproval;
use Modules\Sales\Infrastructure\UnavailableCashSession;
use Modules\Sales\Infrastructure\UnavailableCriticalSalesWrites;
use Modules\Sales\Infrastructure\UnavailableLineRemoval;
use Nwidart\Modules\Support\ModuleServiceProvider;

class SalesServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        parent::register();
        $this->app->bind(SalesStore::class, EloquentSalesStore::class);
        $this->app->bind(SalesOperations::class, ManageSales::class);
        $this->app->bind(QuantityLimits::class, GlobalQuantityLimits::class);
        $this->app->bindIf(ActiveCashSession::class, UnavailableCashSession::class);
        $this->app->bindIf(LineRemovalApproval::class, UnavailableLineRemoval::class);
        $this->app->bindIf(CriticalSalesWrites::class, UnavailableCriticalSalesWrites::class);
        $this->app->bindIf(CancellationApproval::class, UnavailableCancellationApproval::class);
    }

    public function boot(): void
    {
        parent::boot();
        $this->app->make(ApiExceptionMappings::class)->register(SalesConflict::class, ApiCode::CONFLICT);
        $this->app->make(ApiExceptionMappings::class)->register(SalesDependencyUnavailable::class, ApiCode::SERVICE_UNAVAILABLE);
        $this->app->make(ApiExceptionMappings::class)->register(CashUnavailable::class, ApiCode::SERVICE_UNAVAILABLE);
    }

    protected string $name = 'Sales';

    protected string $nameLower = 'sales';

    /** @var array<class-string<ServiceProvider>> */
    protected array $providers = [
        RouteServiceProvider::class,
    ];
}
