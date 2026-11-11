<?php

use Illuminate\Support\Facades\Route;
use Nwidart\Modules\Facades\Module;

Route::prefix('v1')->name('api.v1.')->middleware('throttle:api-v1')->group(function (): void {
    foreach (Module::allEnabled() as $module) {
        $routes = module_path($module->getStudlyName(), 'routes/api/v1.php');
        if (is_file($routes)) {
            Route::name($module->getLowerName().'.')->group($routes);
        }
    }
});
