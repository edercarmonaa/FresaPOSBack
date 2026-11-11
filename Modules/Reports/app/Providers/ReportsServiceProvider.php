<?php

namespace Modules\Reports\Providers;

use Illuminate\Support\ServiceProvider;
use Nwidart\Modules\Support\ModuleServiceProvider;

class ReportsServiceProvider extends ModuleServiceProvider
{
    protected string $name = 'Reports';

    protected string $nameLower = 'reports';

    /** @var array<class-string<ServiceProvider>> */
    protected array $providers = [
        RouteServiceProvider::class,
    ];
}
