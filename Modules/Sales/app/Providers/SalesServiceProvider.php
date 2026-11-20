<?php

namespace Modules\Sales\Providers;

use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use Modules\Cash\Contracts\ActiveCashSession;
use Modules\Cash\Contracts\ConfirmedSalesCash;
use Modules\Cash\Contracts\SessionSales;
use Modules\Core\Contracts\ApiCode;
use Modules\Core\Contracts\ApiExceptionMappings;
use Modules\Sales\Application\Ports\SalesStore;
use Modules\Sales\Application\Services\CheckoutSale;
use Modules\Sales\Application\Services\CorrectPaidSale;
use Modules\Sales\Application\Services\DurableSalesWrites;
use Modules\Sales\Application\Services\ManageSaleReasons;
use Modules\Sales\Application\Services\ManageSales;
use Modules\Sales\Application\Services\ReadConfirmedSaleDocument;
use Modules\Sales\Contracts\CancellationApproval;
use Modules\Sales\Contracts\ConfirmedSalesDocuments;
use Modules\Sales\Contracts\CriticalSalesWrites;
use Modules\Sales\Contracts\DiscountApproval;
use Modules\Sales\Contracts\LineRemovalApproval;
use Modules\Sales\Contracts\QuantityLimits;
use Modules\Sales\Contracts\SaleCheckout;
use Modules\Sales\Contracts\SaleCorrections;
use Modules\Sales\Contracts\SaleOperationReasons;
use Modules\Sales\Contracts\SalesOperations;
use Modules\Sales\Domain\CashUnavailable;
use Modules\Sales\Domain\SalesConflict;
use Modules\Sales\Domain\SalesDependencyUnavailable;
use Modules\Sales\Http\Livewire\CheckoutPanel;
use Modules\Sales\Http\Livewire\CorrectionPanel;
use Modules\Sales\Http\Livewire\DiscountPanel;
use Modules\Sales\Http\Livewire\PointOfSale;
use Modules\Sales\Http\Livewire\ReasonAdministration;
use Modules\Sales\Http\Livewire\SaleActionsPanel;
use Modules\Sales\Http\Middleware\PosAccess;
use Modules\Sales\Infrastructure\AuthorizedDiscounts;
use Modules\Sales\Infrastructure\AuthorizedSaleReasons;
use Modules\Sales\Infrastructure\GlobalQuantityLimits;
use Modules\Sales\Infrastructure\Persistence\ConfirmedCashIncome;
use Modules\Sales\Infrastructure\Persistence\EloquentSalesStore;
use Modules\Sales\Infrastructure\Persistence\SessionSalesSummary;
use Modules\Sales\Infrastructure\UnavailableCashSession;
use Nwidart\Modules\Support\ModuleServiceProvider;

class SalesServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        parent::register();
        $this->app->bind(SalesStore::class, EloquentSalesStore::class);
        $this->app->bind(SalesOperations::class, ManageSales::class);
        $this->app->bind(ConfirmedSalesDocuments::class, ReadConfirmedSaleDocument::class);
        $this->app->bind(ConfirmedSalesCash::class, ConfirmedCashIncome::class);
        $this->app->bind(SessionSales::class, SessionSalesSummary::class);
        $this->app->bind(SaleCheckout::class, CheckoutSale::class);
        $this->app->bind(SaleCorrections::class, CorrectPaidSale::class);
        $this->app->bind(DiscountApproval::class, AuthorizedDiscounts::class);
        $this->app->bind(QuantityLimits::class, GlobalQuantityLimits::class);
        $this->app->bindIf(ActiveCashSession::class, UnavailableCashSession::class);
        $this->app->bind(SaleOperationReasons::class, ManageSaleReasons::class);
        $this->app->bind(LineRemovalApproval::class, AuthorizedSaleReasons::class);
        $this->app->bind(CriticalSalesWrites::class, DurableSalesWrites::class);
        $this->app->bind(CancellationApproval::class, AuthorizedSaleReasons::class);
    }

    public function boot(): void
    {
        parent::boot();
        Livewire::component('sales.pos', PointOfSale::class);
        Livewire::component('sales.checkout', CheckoutPanel::class);
        Livewire::component('sales.corrections', CorrectionPanel::class);
        Livewire::component('sales.reasons', ReasonAdministration::class);
        Livewire::component('sales.operations', SaleActionsPanel::class);
        Livewire::component('sales.discounts', DiscountPanel::class);
        Livewire::addPersistentMiddleware([PosAccess::class]);
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
