<?php

namespace Modules\Core\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Modules\Core\Contracts\ApiExceptionMappings;
use Modules\Core\Contracts\ApiResponder;
use Modules\Core\Contracts\TransactionManager;
use Modules\Core\Http\ApiExceptionHandler;
use Modules\Core\Http\ApiResponseFactory;
use Modules\Core\Http\ExceptionMappings;
use Modules\Core\Infrastructure\Persistence\LaravelTransactionManager;
use Nwidart\Modules\Support\ModuleServiceProvider;

class CoreServiceProvider extends ModuleServiceProvider
{
    protected string $name = 'Core';

    protected string $nameLower = 'core';

    /** @var array<class-string<ServiceProvider>> */
    protected array $providers = [
        RouteServiceProvider::class,
    ];

    public function boot(): void
    {
        parent::boot();
        RateLimiter::for('api-v1', fn (Request $request) => Limit::perMinute(60)->by($request->ip()));
    }

    public function register(): void
    {
        parent::register();
        $this->app->singleton(ApiResponseFactory::class);
        $this->app->alias(ApiResponseFactory::class, ApiResponder::class);
        $this->app->singleton(ApiExceptionMappings::class, ExceptionMappings::class);
        $this->app->singleton(ApiExceptionHandler::class);

        $this->app->singleton(TransactionManager::class, LaravelTransactionManager::class);
    }
}
