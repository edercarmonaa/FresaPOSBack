<?php

use Illuminate\Auth\Access\Gate as AccessGate;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Modules\Users\Infrastructure\Persistence\Models\User;
use Tests\Fixtures\UI07A\FixtureData;

require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config(['database.default' => 'ui07a_no_database', 'database.connections' => [], 'session.driver' => 'array']);
URL::forceRootUrl('http://127.0.0.1:8097');
$user = new User;
$user->forceFill(['id' => '01ARZ3NDEKTSV4RRFFQ69G5FAV', 'name' => 'UI fixture', 'username' => 'ui_fixture']);
auth('web')->setUser($user);
Gate::swap(new AccessGate($app, fn () => $user));
Gate::before(fn () => true);
$app['view']->addNamespace('ui07legacy', getcwd().'/tests/Fixtures/UI07A');
foreach (['before', 'after'] as $phase) {
    foreach (['products-list', 'products-create', 'products-edit', 'products-empty', 'products-deleted', 'categories-list', 'categories-edit', 'categories-empty'] as $case) {
        [$entity, $state] = explode('-', $case);
        $data = FixtureData::make($state);
        request()->setRouteResolver(fn () => (new Route('GET', '/', fn () => null))->name('catalog.'.$entity));
        $directory = getcwd().($phase === 'before' ? '/tests/Fixtures/UI07A/' : '/Modules/Catalog/resources/views/');
        $source = file_get_contents($directory.'livewire-'.$entity.'.blade.php');
        if ($phase === 'before') {
            $source = str_replace('catalog::', 'ui07legacy::', $source);
        }
        $body = Blade::render($source, $data);
        $page = str_replace('<livewire:catalog.'.$entity.' />', $body, file_get_contents($directory.$entity.'.blade.php'));
        $html = Blade::render($page, $data);
        $html = preg_replace('~(<main[^>]*>)~', '$1<p role="note" style="margin-bottom:24px">UI-07A · Fixture '.$phase.' · Datos ficticios y campos rellenados para QA. Sin Livewire operativo, sesión ni operaciones.</p>', $html);
        $html = preg_replace('/((?:name="csrf-token" content|data-csrf|name="_token" value)=")[^"]*/', '$1UI07A_STATIC_NO_SESSION', $html);
        $html = str_replace('</head>', '<style>[wire\\:loading]{display:none!important}</style></head>', $html);
        // Solo QA: representar los valores de los bindings sin hidratar o ejecutar Livewire.
        $values = [...$data, 'search' => '', 'stateFilter' => '', 'perPage' => '25', 'categorySearch' => '', 'categoryFilter' => '', 'sort' => 'name', 'direction' => 'asc', 'deleted' => $state === 'deleted' ? 'only' : 'without', 'price' => '1234.5678', 'categoryId' => $data['originalCategory']?->id ?? $data['activeCategories'][0]->id];
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
        file_put_contents(getcwd().'/docs/ui-ux/qa/ui-07a/fixtures/'.$phase.'-'.$case.'.html', $document->saveHTML());
    }
}
echo "Dieciséis fixtures estáticas de Catálogo; sin SQL ni operaciones.\n";
