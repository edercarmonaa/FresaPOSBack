<?php

use Illuminate\Auth\Access\Gate as AccessGate;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Modules\Users\Infrastructure\Persistence\Models\User;
use Tests\Fixtures\UI07B\FixtureData;

require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config(['database.default' => 'ui07b_no_database', 'database.connections' => [], 'session.driver' => 'array']);
URL::forceRootUrl('http://127.0.0.1:8098');
$user = new User;
$user->forceFill(['id' => '01ARZ3NDEKTSV4RRFFQ69G5FAV', 'name' => 'UI fixture', 'username' => 'ui_fixture']);
auth('web')->setUser($user);
Gate::swap(new AccessGate($app, fn () => $user));
Gate::before(fn () => true);
$app['view']->addNamespace('ui07legacy', getcwd().'/tests/Fixtures/UI07B');
foreach (['before', 'after'] as $phase) {
    foreach (['list', 'create', 'edit', 'submitted', 'activate', 'deactivate', 'error', 'empty', 'last-page'] as $state) {
        $case = $state;
        $data = FixtureData::make($state);
        request()->setRouteResolver(fn () => (new Route('GET', '/', fn () => null))->name('sales.reasons'));
        $directory = getcwd().($phase === 'before' ? '/tests/Fixtures/UI07B/' : '/Modules/Sales/resources/views/');
        $source = file_get_contents($directory.($phase === 'before' ? 'reason-administration.blade.php' : 'livewire/reason-administration.blade.php'));
        $body = Blade::render($source, $data);
        $page = str_replace('<livewire:sales.reasons />', $body, file_get_contents($directory.'reasons.blade.php'));
        $html = Blade::render($page, $data);
        $html = preg_replace('~(<main[^>]*>)~', '$1<p role="note" style="margin-bottom:24px">UI-07B · Fixture '.$phase.' · Datos ficticios y campos rellenados para QA. Sin Livewire operativo, sesión ni operaciones.</p>', $html);
        $html = preg_replace('/((?:name="csrf-token" content|data-csrf|name="_token" value)=")[^"]*/', '$1UI07B_STATIC_NO_SESSION', $html);
        $html = str_replace('</head>', '<style>[wire\\:loading],[wire\\:offline]{display:none!important}</style></head>', $html);
        // Solo QA: representar los valores de los bindings sin hidratar o ejecutar Livewire.
        $values = $data;
        $document = new DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET);
        foreach ((new DOMXPath($document))->query('//input | //select | //textarea') as $node) {
            foreach ($node->attributes as $attribute) {
                if (! str_starts_with($attribute->name, 'wire:model')) {
                    continue;
                }
                $value = $values[$attribute->value] ?? null;
                if ($value === null || $node->getAttribute('type') === 'file') {
                    continue;
                }
                if ($node->nodeName === 'select') {
                    foreach ($node->getElementsByTagName('option') as $option) {
                        $option->removeAttribute('selected');
                        if ($option->getAttribute('value') === (string) $value) {
                            $option->setAttribute('selected', 'selected');
                        }
                    }
                } elseif ($node->nodeName === 'textarea') {
                    $node->textContent = (string) $value;
                } elseif ($node->getAttribute('type') === 'checkbox') {
                    if ($value) {
                        $node->setAttribute('checked', 'checked');
                    }
                } else {
                    $node->setAttribute('value', (string) $value);
                }
            }
        }
        file_put_contents(getcwd().'/docs/ui-ux/qa/ui-07b/fixtures/'.$phase.'-'.$case.'.html', $document->saveHTML());
    }
}
echo "Dieciocho fixtures estáticas de Motivos; sin SQL ni operaciones.\n";
