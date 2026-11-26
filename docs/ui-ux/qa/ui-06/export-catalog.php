<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\URL;

require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config(['database.default' => 'ui06_no_database', 'database.connections' => [], 'session.driver' => 'array']);
URL::forceRootUrl('http://127.0.0.1:8096');
$app['view']->addNamespace('ui06qa', getcwd().'/docs/ui-ux/catalogo/ui-06');
$html = view('ui06qa::catalog')->render();
file_put_contents(getcwd().'/docs/ui-ux/catalogo/ui-06/index.html', $html);
echo "Catálogo UI-06 exportado sin conexión SQL.\n";
