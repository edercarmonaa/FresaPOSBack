<?php

use Illuminate\Auth\Access\Gate as AccessGate;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Modules\Users\Infrastructure\Persistence\Models\User;

// Solo QA: identidad y permisos en memoria; ninguna sesión o dato operativo.
require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
URL::forceRootUrl('http://127.0.0.1:8093');
$user = new User;
$user->forceFill(['id' => '01ARZ3NDEKTSV4RRFFQ69G5FAV', 'name' => 'UI fixture', 'username' => 'ui_fixture']);
auth('web')->setUser($user);
Gate::swap(new AccessGate($app, fn () => $user));
Gate::before(fn () => true);
$directory = getcwd().'/docs/ui-ux/qa/ui-03/fixtures';
if (! is_dir($directory)) {
    mkdir($directory, 0777, true);
}
foreach (['admin' => 'catalog.products', 'pos' => 'sales.pos', 'reports' => 'reports.index'] as $context => $routeName) {
    request()->setRouteResolver(fn () => (new Route('GET', '/', fn () => null))->name($routeName));
    $html = Blade::render('@extends("layouts.app") @section("content") <div class="ui-alert ui-alert--info" role="note">UI-03 · Fixture estática de QA. Sin sesión, base de datos u operaciones reales.</div><h1 class="ui-heading" style="margin-top:24px">Revisión del layout '.$context.'</h1><p class="ui-muted" style="margin-top:16px">Se comprueban navegación y preferencias del layout real. No es una pantalla operativa ni añade funciones al sistema.</p> @endsection');
    $html = preg_replace('/(name="csrf-token" content=")[^"]*/', '$1UI03_STATIC_NO_SESSION', $html);
    $html = preg_replace('/(data-csrf=")[^"]*/', '$1UI03_STATIC_NO_SESSION', $html);
    file_put_contents($directory.'/'.$context.'.html', $html);
}
echo "Fixtures estáticas exportadas, sin credenciales.\n";
