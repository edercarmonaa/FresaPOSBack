<?php

namespace Modules\Cash\Providers;

use Illuminate\Support\ServiceProvider;
use Nwidart\Modules\Support\ModuleServiceProvider;

class CashServiceProvider extends ModuleServiceProvider
{
    protected string $name = 'Cash';

    protected string $nameLower = 'cash';

    /** @var array<class-string<ServiceProvider>> */
    protected array $providers = [
        RouteServiceProvider::class,
    ];
}
