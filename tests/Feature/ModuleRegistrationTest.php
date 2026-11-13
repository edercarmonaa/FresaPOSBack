<?php

namespace Tests\Feature;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Route;
use Modules\Auth\Http\Controllers\ApiAuthenticationController;
use Modules\Auth\Http\Controllers\WebAuthenticationController;
use Modules\Catalog\Http\Controllers\CategoryController;
use Modules\Catalog\Http\Controllers\ProductController;
use Modules\Sales\Http\Controllers\SaleController;
use Modules\Sales\Http\Controllers\TableController;
use Tests\Support\ModuleDependencies;
use Tests\TestCase;

class ModuleRegistrationTest extends TestCase
{
    public function test_all_thirteen_modules_register_their_providers_resources_and_migration_paths(): void
    {
        $modules = $this->app->make('modules')->allEnabled();
        $this->assertEqualsCanonicalizing(ModuleDependencies::NAMES, array_values(array_map(fn ($module) => $module->getStudlyName(), $modules)));
        $migrationPaths = $this->app->make('migrator')->paths();
        $views = $this->app->make('view')->getFinder()->getHints();

        foreach (ModuleDependencies::NAMES as $name) {
            $provider = "Modules\\$name\\Providers\\{$name}ServiceProvider";
            $routeProvider = "Modules\\$name\\Providers\\RouteServiceProvider";
            $this->assertTrue($this->app->providerIsLoaded($provider), $provider);
            $this->assertTrue($this->app->providerIsLoaded($routeProvider), $routeProvider);
            $this->assertSame($name, config(strtolower($name).'.name'));
            $this->assertContains(module_path($name, 'database/migrations'), $migrationPaths);
            $this->assertContains(realpath(module_path($name, 'resources/views')), array_map(realpath(...), $views[strtolower($name)]));
            foreach (['app/Domain', 'app/Application', 'app/Infrastructure', 'app/Http'] as $layer) {
                $path = module_path($name, $layer);
                $files = is_dir($path) ? (new Filesystem)->allFiles($path) : [];
                $this->assertTrue(! is_dir($path) || count($files) > 0, "$name/$layer no debe existir vacío.");
            }
        }
        // No hay endpoints modulares de ejemplo ni convenciones API introducidas.
        $moduleRoutes = array_filter(Route::getRoutes()->getRoutes(), function ($route): bool {
            return str_starts_with($route->getActionName(), 'Modules\\');
        });
        foreach ($moduleRoutes as $route) {
            $this->assertContains(explode('@', $route->getActionName())[0], [
                WebAuthenticationController::class,
                ApiAuthenticationController::class,
                CategoryController::class,
                ProductController::class,
                SaleController::class,
                TableController::class,
            ]);
        }
    }

    public function test_routes_and_tests_are_discoverable_without_placeholder_endpoints(): void
    {
        foreach (ModuleDependencies::NAMES as $name) {
            $routeFile = module_path($name, 'routes/web.php');
            $this->assertFileExists($routeFile);
            $this->assertDirectoryExists(module_path($name, 'tests/Feature'));
            $migrations = (new Filesystem)->files(module_path($name, 'database/migrations'));
            $this->assertSame(
                match ($name) {
                    'Users' => ['2026_11_11_000001_create_user_security_tables.php'],
                    'Auth' => ['2026_11_11_000002_create_auth_access_tables.php'],
                    'Audit' => ['2026_11_11_000003_create_security_events.php', '2026_11_12_000004_add_catalog_change_data_to_security_events.php'],
                    'Catalog' => ['2026_11_12_000005_create_catalog_tables.php'],
                    'Sales' => ['2026_11_13_000006_create_sales_tables.php'],
                    default => [],
                },
                array_map(fn ($file) => $file->getFilename(), $migrations),
            );
        }
    }
}
