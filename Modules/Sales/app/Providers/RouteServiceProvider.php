<?php

namespace Modules\Sales\Providers;

use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        parent::boot();

        $this->routes(function (): void {
            Route::middleware('web')->group(module_path('Sales', '/routes/web.php'));
        });
    }
}
