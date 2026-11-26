<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Blade;

// Ejecutar desde la raíz. Renderiza documentación con los componentes Blade reales.
require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$source = file_get_contents(getcwd().'/docs/ui-ux/catalogo/ui-02/catalog.blade.php');
file_put_contents(getcwd().'/docs/ui-ux/catalogo/ui-02/index.html', Blade::render($source));
echo "Catálogo Blade exportado.\n";
