<?php

use Illuminate\Auth\Access\Gate as AccessGate;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Modules\Users\Infrastructure\Persistence\Models\User;
use Tests\Fixtures\UI08A\FixtureData;

require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config(['database.default' => 'ui08a_no_database', 'database.connections' => [], 'session.driver' => 'array']);
URL::forceRootUrl('http://127.0.0.1:8101');
$user = new User;
$user->forceFill(['id' => '01ARZ3NDEKTSV4RRFFQ69G5FAV', 'name' => 'QA UI-08A', 'username' => 'ui_fixture']);
auth('web')->setUser($user);
Gate::swap(new AccessGate($app, fn () => $user));
Gate::before(fn () => true);
Schema::swap(new class
{
    public function hasTable(string $table): bool
    {
        return true;
    }

    public function hasColumn(string $table, string $column): bool
    {
        return true;
    }
});
$directory = getcwd().'/docs/ui-ux/qa/ui-08a/fixtures';
foreach (['before', 'after'] as $phase) {
    foreach (FixtureData::STATES as $state) {
        request()->setRouteResolver(fn () => (new Route('GET', '/', fn () => null))->name('sales.pos'));
        $body = Blade::render(FixtureData::source($phase === 'before'), FixtureData::make($state));
        $shell = file_get_contents(base_path($phase === 'before' ? 'tests/Fixtures/UI08A/pos.blade.php' : 'Modules/Sales/resources/views/pos.blade.php'));
        $shell = str_replace('<livewire:sales.pos />', $body, $shell);
        $html = Blade::render($shell);
        // Blade::render anidado no conserva el indicador de recursos de Livewire.
        // Solo QA: cargar el mismo bundle instalado para ejercitar Alpine sin hidratar componentes.
        $html = str_replace('</body>', '<script src="/livewire-00000000/livewire.js" data-csrf="UI08A_STATIC_NO_SESSION" data-update-uri="/qa-no-post"></script></body>', $html);
        $html = preg_replace('~(<main[^>]*>)~', '$1<p role="note">UI-08A · Fixture visual '.$phase.'. Datos ficticios, sin SQL ni operaciones reales. Paneles financieros sustituidos solo en esta fixture.</p><style>[wire\\:loading],[wire\\:loading\\.delay],[wire\\:offline]{display:none!important}</style>', $html);
        $html = preg_replace('/((?:name="csrf-token" content|data-csrf|name="_token" value)=")[^"]*/', '$1UI08A_STATIC_NO_SESSION', $html);
        file_put_contents($directory.'/'.$phase.'-'.$state.'.html', $html);
    }
}
echo "Exportadas 32 fixtures visuales sin conexión SQL.\n";
