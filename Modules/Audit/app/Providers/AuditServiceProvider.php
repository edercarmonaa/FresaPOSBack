<?php

namespace Modules\Audit\Providers;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Modules\Audit\Application\RecordSecurityEvent;
use Modules\Core\Contracts\SecurityEvent;
use Nwidart\Modules\Support\ModuleServiceProvider;

class AuditServiceProvider extends ModuleServiceProvider
{
    public function boot(): void
    {
        parent::boot();
        Event::listen(SecurityEvent::class, [RecordSecurityEvent::class, 'handle']);
    }

    protected string $name = 'Audit';

    protected string $nameLower = 'audit';

    /** @var array<class-string<ServiceProvider>> */
    protected array $providers = [
        RouteServiceProvider::class,
    ];
}
