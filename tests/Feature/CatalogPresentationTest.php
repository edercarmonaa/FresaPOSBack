<?php

namespace Tests\Feature;

use DOMDocument;
use DOMXPath;
use Illuminate\Auth\Access\Gate as AccessGate;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Gate;
use Modules\Users\Infrastructure\Persistence\Models\User;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\UI07A\FixtureData;
use Tests\TestCase;

class CatalogPresentationTest extends TestCase
{
    public static function permissions(): array
    {
        return ['none' => [[]], 'create' => [['catalog.products.create']], 'update' => [['catalog.products.update']],
            'deactivate' => [['catalog.products.deactivate']], 'restore' => [['catalog.products.restore']],
            'all' => [['catalog.products.create', 'catalog.products.update', 'catalog.products.deactivate', 'catalog.products.restore']]];
    }

    #[DataProvider('permissions')]
    public function test_catalog_rendering_keeps_fields_actions_images_and_permission_conditions(array $permissions): void
    {
        $user = new User;
        $this->actingAs($user, 'web');
        Gate::swap(new AccessGate($this->app, fn () => $user));
        Gate::before(fn ($user, string $ability) => in_array($ability, $permissions, true));
        $this->app['view']->addNamespace('ui07legacy', base_path('tests/Fixtures/UI07A'));
        foreach (['list', 'create', 'edit', 'empty', 'deleted'] as $state) {
            foreach (['livewire-products', 'livewire-categories'] as $name) {
                $data = FixtureData::make($state);
                $old = Blade::render(str_replace('catalog::', 'ui07legacy::', file_get_contents(base_path('tests/Fixtures/UI07A/'.$name.'.blade.php'))), $data);
                $new = view('catalog::'.$name, $data)->render();
                $this->assertSame($this->contract($old), $this->contract($new), $name.' '.$state);
            }
        }
    }

    public function test_pagination_boundaries_preserve_original_disabled_controls(): void
    {
        foreach ([1, 2] as $page) {
            $data = FixtureData::make();
            $data['page'] = $page;
            $old = Blade::render(file_get_contents(base_path('tests/Fixtures/UI07A/pagination.blade.php')), $data);
            $new = view('catalog::pagination', $data)->render();
            $this->assertSame($this->contract($old), $this->contract($new));
        }
    }

    private function contract(string $html): array
    {
        $document = new DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET);
        $dom = new DOMXPath($document);
        $controls = [];
        foreach ($dom->query('//input | //textarea | //select | //option | //button | //form | //img | //tr[@*[name()="wire:key"]] | //*[@role] | //p[@aria-live]') as $node) {
            $attributes = [];
            foreach ($node->attributes as $attribute) {
                if (! in_array($attribute->name, ['class', 'style'], true)) {
                    $attributes[$attribute->name] = in_array($attribute->name, ['required', 'disabled', 'selected', 'checked', 'multiple', 'readonly'], true) ? true : $attribute->value;
                }
            }
            // aria-label on the new table region is additional semantics, not an existing action.
            if ($node->getAttribute('role') === 'region') {
                continue;
            }
            if ($node->nodeName === 'textarea') {
                $attributes['content'] = $node->textContent;
            }
            if ($node->nodeName === 'input') {
                $attributes['type'] = $node->getAttribute('type') ?: 'text';
            }
            ksort($attributes);
            $controls[] = [$node->nodeName, $attributes];
        }
        $text = [];
        foreach ($dom->query('//text()') as $node) {
            $value = trim(preg_replace('/\s+/u', ' ', $node->textContent));
            if ($value !== '') {
                $text[] = $value;
            }
        }

        return compact('controls', 'text');
    }
}
