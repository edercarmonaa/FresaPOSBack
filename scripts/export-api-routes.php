<?php

use Illuminate\Contracts\Console\Kernel;

require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$operations = [];
foreach ($app->make('router')->getRoutes() as $route) {
    if (! str_starts_with($route->uri(), 'api/v1/')) {
        continue;
    }
    foreach (array_diff($route->methods(), ['HEAD']) as $method) {
        $operations[] = ['method' => strtolower($method), 'path' => '/'.substr($route->uri(), 7),
            'name' => $route->getName(), 'middleware' => $route->gatherMiddleware()];
    }
}
echo json_encode($operations, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
