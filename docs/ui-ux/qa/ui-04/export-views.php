<?php

use Illuminate\Auth\Access\Gate as AccessGate;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Modules\Users\Infrastructure\Persistence\Models\User;

require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
// QA visual exclusivamente: cualquier consulta SQL accidental falla.
config(['database.default' => 'ui04_no_database', 'database.connections' => [], 'session.driver' => 'array']);
URL::forceRootUrl('http://127.0.0.1:8094');
$user = new User;
$user->forceFill(['id' => '01ARZ3NDEKTSV4RRFFQ69G5FAV', 'name' => 'Persona de prueba UI-04', 'username' => 'ui_fixture']);
auth('web')->setUser($user);
Gate::swap(new AccessGate($app, fn () => $user));
Gate::before(fn () => true);
$data = [
    'identity' => (object) ['name' => 'Persona de prueba UI-04', 'username' => 'ui_fixture', 'roles' => ['cashier']],
    'status' => '', 'errors' => new ViewErrorBag,
    'sessions' => array_map(fn ($state) => [
        'id' => '01ARZ3NDEKTSV4RRFFQ69G5FAV', 'channel' => 'web', 'client_name' => 'Terminal de prueba',
        'created_at' => '2026-11-26 12:00:00', 'last_activity_at' => '2026-11-26 12:05:00',
        'expires_at' => '2026-11-26 20:00:00', 'state' => $state,
    ], ['active', 'expired', 'revoked']),
];
$directory = getcwd().'/docs/ui-ux/qa/ui-04/fixtures';
foreach (['before', 'after'] as $phase) {
    $source = fn ($name) => file_get_contents(getcwd().($phase === 'before' ? '/tests/Fixtures/UI04/' : '/Modules/Auth/resources/views/').$name.'.blade.php');
    foreach (['login' => 'login', 'password' => 'auth.password', 'account' => 'auth.account', 'admin' => 'auth.admin'] as $name => $routeName) {
        request()->setRouteResolver(fn () => (new Route('GET', '/', fn () => null))->name($routeName));
        $body = str_replace(['<livewire:auth.login />', '<livewire:auth.sessions />'], [Blade::render($source('livewire-login'), $data), Blade::render($source('livewire-sessions'), $data)], $source($name));
        $html = Blade::render($body, $data);
        $html = str_replace('<main id="ui-main"', '<main data-ui-qa="static-no-session" id="ui-main"', $html);
        $html = preg_replace('~(<main[^>]*>)~', '$1<p role="note">UI-04 · Fixture visual '.$phase.'. Datos ficticios; sin sesión ni operaciones reales.</p>', $html);
        $html = preg_replace('/((?:name="csrf-token" content|data-csrf|name="_token" value)=")[^"]*/', '$1UI04_STATIC_NO_SESSION', $html);
        file_put_contents($directory.'/'.$phase.'-'.$name.'.html', $html);
    }
}
foreach (['login-error' => 'login', 'account-empty' => 'account'] as $case => $name) {
    $data['sessions'] = [];
    $data['status'] = 'Estado de prueba: sin operaciones reales.';
    $data['errors']->put('default', new MessageBag(['username' => 'No fue posible completar el acceso. Intenta nuevamente.']));
    request()->setRouteResolver(fn () => (new Route('GET', '/', fn () => null))->name($name === 'login' ? 'login' : 'auth.account'));
    $body = str_replace(['<livewire:auth.login />', '<livewire:auth.sessions />'], [Blade::render($source('livewire-login'), $data), Blade::render($source('livewire-sessions'), $data)], $source($name));
    $html = Blade::render($body, $data);
    $html = preg_replace('~(<main[^>]*>)~', '$1<p role="note">UI-04 · Estado ficticio de QA; sin sesión ni operaciones reales.</p>', $html);
    $html = preg_replace('/((?:name="csrf-token" content|data-csrf|name="_token" value)=")[^"]*/', '$1UI04_STATIC_NO_SESSION', $html);
    file_put_contents($directory.'/after-'.$case.'.html', $html);
}
echo "Exportadas diez fixtures estáticas sin conexión SQL.\n";
