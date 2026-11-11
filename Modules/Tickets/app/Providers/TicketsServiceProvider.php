<?php

namespace Modules\Tickets\Providers;

use Illuminate\Support\ServiceProvider;
use Nwidart\Modules\Support\ModuleServiceProvider;

class TicketsServiceProvider extends ModuleServiceProvider
{
    protected string $name = 'Tickets';

    protected string $nameLower = 'tickets';

    /** @var array<class-string<ServiceProvider>> */
    protected array $providers = [
        RouteServiceProvider::class,
    ];
}
