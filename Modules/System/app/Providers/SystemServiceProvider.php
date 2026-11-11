<?php

namespace Modules\System\Providers;

use Illuminate\Support\ServiceProvider;
use Nwidart\Modules\Support\ModuleServiceProvider;

class SystemServiceProvider extends ModuleServiceProvider
{
    protected string $name = 'System';

    protected string $nameLower = 'system';

    /** @var array<class-string<ServiceProvider>> */
    protected array $providers = [
        RouteServiceProvider::class,
    ];
}
