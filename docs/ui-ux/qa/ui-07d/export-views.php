<?php

use Illuminate\Auth\Access\Gate as AccessGate;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Modules\Reports\Domain\ReportDefinition;
use Modules\Users\Infrastructure\Persistence\Models\User;
use Tests\Fixtures\UI07D\FixtureData;

require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config(['database.default' => 'ui07d_no_database', 'database.connections' => [], 'session.driver' => 'array']);
URL::forceRootUrl('http://127.0.0.1:8100');
$user = new User;
$user->forceFill(['id' => '01ARZ3NDEKTSV4RRFFQ69G5FAV', 'name' => 'QA UI-07D', 'username' => 'ui_fixture']);
auth('web')->setUser($user);
Gate::swap(new AccessGate($app, fn () => $user));
Gate::before(fn () => true);
$directory = getcwd().'/docs/ui-ux/qa/ui-07d/fixtures';
foreach (['before', 'after'] as $phase) {
    foreach ([...array_keys(ReportDefinition::NAMES), 'empty', 'no-export', 'limits', 'pdf-limit', 'pdf-overflow', 'xlsx-limit', 'last-page', 'preset', 'error', 'error-validation'] as $name) {
        request()->setRouteResolver(fn () => (new Route('GET', '/', fn () => null))->name('reports.show'));
        Gate::swap(new AccessGate($app, fn () => $user));
        Gate::before(fn ($user, string $ability) => $ability !== 'reports.export' || $name !== 'no-export');
        $error = str_starts_with($name, 'error');
        $type = array_key_exists($name, ReportDefinition::NAMES) ? $name : ($name === 'empty' ? 'summary' : 'sales');
        $state = ['limits' => 'xlsx-overflow', 'last-page' => 'last'][$name] ?? $name;
        $data = $error ? ['message' => $name === 'error' ? 'No se pudo completar el reporte. Los datos financieros permanecen conservados.' : 'Revisa los filtros, el rango máximo de 366 días y el límite de exportación: PDF 300 filas, Excel 1000 filas.', 'validation' => $name === 'error-validation' ? ['from' => ['Selecciona una fecha inicial válida.'], 'until' => ['Revisa la fecha final.']] : []] : ['data' => FixtureData::make($type, $state), 'reportNames' => ReportDefinition::NAMES];
        $view = $error ? 'error' : 'index';
        $source = file_get_contents(getcwd().($phase === 'before' ? '/tests/Fixtures/UI07D/' : '/Modules/Reports/resources/views/').$view.'.blade.php');
        $html = Blade::render($source, $data);
        $html = preg_replace('~(<main[^>]*>)~', '$1<p role="note" style="margin-bottom:24px">UI-07D · Fixture visual '.$phase.'. Datos ficticios; sin sesión ni operaciones reales.</p>', $html);
        $html = preg_replace('/((?:name="csrf-token" content|data-csrf|name="_token" value)=")[^"]*/', '$1UI07D_STATIC_NO_SESSION', $html);
        file_put_contents($directory.'/'.$phase.'-'.$name.'.html', $html);
    }
}
echo "Exportadas 38 fixtures visuales sin conexión SQL.\n";
