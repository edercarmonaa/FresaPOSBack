<?php

use Illuminate\Auth\Access\Gate as AccessGate;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Modules\Reports\Domain\ReportDefinition;
use Modules\Users\Infrastructure\Persistence\Models\User;
use Tests\Fixtures\UI05\ReportFixture;

require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config(['database.default' => 'ui05_no_database', 'database.connections' => [], 'session.driver' => 'array']);
URL::forceRootUrl('http://127.0.0.1:8095');
$user = new User;
$user->forceFill(['id' => '01ARZ3NDEKTSV4RRFFQ69G5FAV', 'name' => 'QA UI-05', 'username' => 'ui_fixture']);
auth('web')->setUser($user);
Gate::swap(new AccessGate($app, fn () => $user));
Gate::before(fn () => true);
$directory = getcwd().'/docs/ui-ux/qa/ui-05/fixtures';
foreach (['before', 'after'] as $phase) {
    foreach (['summary', 'sales', 'payments', 'cash-sessions', 'empty', 'error'] as $name) {
        request()->setRouteResolver(fn () => (new Route('GET', '/', fn () => null))->name('reports.show'));
        $type = $name === 'empty' ? 'summary' : $name;
        $data = $name === 'error' ? ['message' => 'Revisa los filtros y el rango máximo de 366 días.', 'validation' => ['from' => ['Selecciona un rango válido.']]] : ['data' => ReportFixture::make($type, $name === 'empty' ? 0 : 2), 'reportNames' => ReportDefinition::NAMES];
        $view = $name === 'error' ? 'error' : 'index';
        $source = file_get_contents(getcwd().($phase === 'before' ? '/tests/Fixtures/UI05/' : '/Modules/Reports/resources/views/').$view.'.blade.php');
        $html = Blade::render($source, $data);
        if ($phase === 'before') {
            // Reconstruye el límite cromático claro que tenía Reportes antes de UI-05.
            $html = preg_replace('/(<main[^>]*class=")/', '$1ui-legacy-light ', $html);
        }
        $html = preg_replace('~(<main[^>]*>)~', '$1<p role="note" style="margin-bottom:24px">UI-05 · Fixture visual '.$phase.'. Datos ficticios; sin sesión ni operaciones reales.</p>', $html);
        $html = preg_replace('/((?:name="csrf-token" content|data-csrf|name="_token" value)=")[^"]*/', '$1UI05_STATIC_NO_SESSION', $html);
        file_put_contents($directory.'/'.$phase.'-'.$name.'.html', $html);
    }
}
echo "Exportadas doce fixtures visuales sin conexión SQL.\n";
