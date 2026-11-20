<?php

namespace Modules\Tickets\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\Tickets\Application\Services\GenerateSaleReceipt;
use Modules\Tickets\Contracts\SaleReceipts;
use Nwidart\Modules\Support\ModuleServiceProvider;

class TicketsServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        parent::register();
        $this->app->bind(SaleReceipts::class, GenerateSaleReceipt::class);
    }

    protected string $name = 'Tickets';

    protected string $nameLower = 'tickets';

    /** @var array<class-string<ServiceProvider>> */
    protected array $providers = [
        RouteServiceProvider::class,
    ];
}
