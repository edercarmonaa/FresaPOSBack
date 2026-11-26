<?php

use Illuminate\Auth\Access\Gate as AccessGate;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Modules\Users\Infrastructure\Persistence\Models\User;
use Tests\Fixtures\UI07C\FixtureData;

require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config(['database.default' => 'ui07c_no_database', 'database.connections' => [], 'session.driver' => 'array']);
URL::forceRootUrl('http://127.0.0.1:8099');
$user = new User;
$user->forceFill(['id' => '01ARZ3NDEKTSV4RRFFQ69G5FAV', 'name' => 'UI fixture', 'username' => 'ui_fixture']);
auth('web')->setUser($user);
Gate::swap(new AccessGate($app, fn () => $user));
Gate::before(fn () => true);
Schema::swap(new class
{
    public function hasTable(string $table): bool
    {
        return $table === 'cash_reconciliations';
    }
});
foreach (['before', 'after'] as $phase) {
    foreach (['ready', 'open', 'blind', 'pending', 'movement-pending', 'blocked', 'closed', 'error', 'read-only', 'unprovisioned', 'resolved', 'empty'] as $case) {
        $legacy = $phase === 'before';
        $deskState = match ($case) {
            'blind', 'pending' => 'blind', 'closed' => 'ready', 'movement-pending', 'blocked', 'resolved', 'empty', 'read-only' => 'open', default => $case
        };
        $movementState = match ($case) {
            'ready', 'closed', 'unprovisioned' => 'no-session', 'movement-pending' => 'pending', default => $case
        };
        $closingState = match ($case) {
            'closed' => 'shortage', 'ready', 'open', 'movement-pending', 'unprovisioned', 'resolved' => 'idle', default => $case
        };
        $data = FixtureData::desk($deskState);
        if ($case === 'read-only') {
            $data['canOpen'] = false;
            $data['canViewClosings'] = false;
        }
        $movements = FixtureData::movements($movementState);
        $closing = FixtureData::closing($closingState);
        request()->setRouteResolver(fn () => (new Route('GET', '/', fn () => null))->name('cash.desk'));
        $body = Blade::render(FixtureData::source('cash-desk', $legacy), $data);
        $body = str_replace('<span data-fixture-child="movements"></span>', Blade::render(FixtureData::source('movements-panel', $legacy), $movements), $body);
        $body = str_replace('<span data-fixture-child="closing"></span>', Blade::render(FixtureData::source('closing-panel', $legacy), $closing), $body);
        $directory = getcwd().($legacy ? '/tests/Fixtures/UI07C/' : '/Modules/Cash/resources/views/');
        $page = str_replace('<livewire:cash.desk />', $body, file_get_contents($directory.'desk.blade.php'));
        $html = Blade::render($page, $data);
        $html = preg_replace('~(<main[^>]*>)~', '$1<p role="note" style="margin-bottom:24px">UI-07C · Fixture '.$phase.' · Datos ficticios y campos rellenados para QA. Sin Livewire operativo, sesión ni operaciones.</p>', $html);
        $html = preg_replace('/((?:name="csrf-token" content|data-csrf|name="_token" value)=")[^"]*/', '$1UI07C_STATIC_NO_SESSION', $html);
        $html = str_replace('</head>', '<style>[wire\\:loading],[wire\\:loading\\.delay],[wire\\:offline]{display:none!important}</style></head>', $html);
        // Solo QA: representar los valores de los bindings sin hidratar o ejecutar Livewire.
        $values = [...$data, ...$movements, ...$closing];
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
        file_put_contents(getcwd().'/docs/ui-ux/qa/ui-07c/fixtures/'.$phase.'-'.$case.'.html', $document->saveHTML());
    }
}
echo "Veinticuatro fixtures estáticas de Caja; sin SQL ni operaciones.\n";
