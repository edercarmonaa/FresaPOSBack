<?php

namespace Modules\Devices\Providers;

use Illuminate\Support\ServiceProvider;
use Nwidart\Modules\Support\ModuleServiceProvider;

class DevicesServiceProvider extends ModuleServiceProvider
{
    protected string $name = 'Devices';

    protected string $nameLower = 'devices';

    /** @var array<class-string<ServiceProvider>> */
    protected array $providers = [
        RouteServiceProvider::class,
    ];
}
