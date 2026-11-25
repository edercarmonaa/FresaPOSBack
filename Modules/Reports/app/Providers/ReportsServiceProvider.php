<?php

namespace Modules\Reports\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\Reports\Application\QueryOperationalReports;
use Modules\Reports\Contracts\OperationalReports;
use Nwidart\Modules\Support\ModuleServiceProvider;

class ReportsServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        parent::register();
        $this->app->bind(OperationalReports::class, QueryOperationalReports::class);
    }

    protected string $name = 'Reports';

    protected string $nameLower = 'reports';

    /** @var array<class-string<ServiceProvider>> */
    protected array $providers = [
        RouteServiceProvider::class,
    ];
}
