<?php

namespace Tests\Feature;

use DOMDocument;
use DOMXPath;
use Illuminate\Auth\Access\Gate as AccessGate;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Modules\Users\Infrastructure\Persistence\Models\User;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\UI08A\FixtureData;
use Tests\TestCase;

class PosPresentationTest extends TestCase
{
    public static function states(): array
    {
        return array_combine(FixtureData::STATES, array_map(fn ($state) => [$state], FixtureData::STATES));
    }

    #[DataProvider('states')]
    public function test_presentation_preserves_content_actions_bindings_and_guards(string $state): void
    {
        foreach ([true, false] as $permissions) {
            foreach ([true, false] as $schema) {
                Gate::swap(new AccessGate($this->app, fn () => new User));
                Gate::before(fn () => $permissions);
                Schema::swap(new class($schema)
                {
                    public function __construct(private bool $available) {}

                    public function hasTable(string $table): bool
                    {
                        return $this->available;
                    }

                    public function hasColumn(string $table, string $column): bool
                    {
                        return $this->available;
                    }
                });
                $values = FixtureData::make($state, $permissions);
                $old = Blade::render(FixtureData::source(true), $values);
                $new = Blade::render(FixtureData::source(false), $values);
                $this->assertSame($this->contract($old), $this->contract($new), $state);
                $this->assertStringNotContainsString('<script>alert(', $new);
            }
        }
    }

    public function test_operational_script_child_keys_and_shared_partials_are_byte_identical(): void
    {
        $old = file_get_contents(base_path('tests/Fixtures/UI08A/point-of-sale.blade.php'));
        $new = file_get_contents(base_path('Modules/Sales/resources/views/livewire/point-of-sale.blade.php'));
        foreach (['/@script.*?@endscript/s', '/<livewire:[^>]+\/>/'] as $pattern) {
            preg_match_all($pattern, $old, $before);
            preg_match_all($pattern, $new, $after);
            $this->assertNotEmpty($before[0]);
            $this->assertSame($before[0], $after[0]);
        }
        foreach (['styles', 'pagination'] as $partial) {
            $this->assertSame(file_get_contents(base_path('tests/Fixtures/UI08A/'.$partial.'.blade.php')), file_get_contents(base_path('Modules/Sales/resources/views/partials/'.$partial.'.blade.php')));
        }
    }

    public function test_only_explicit_presentation_changes_are_present_in_the_component_source(): void
    {
        $old = file_get_contents(base_path('tests/Fixtures/UI08A/point-of-sale.blade.php'));
        $new = file_get_contents(base_path('Modules/Sales/resources/views/livewire/point-of-sale.blade.php'));
        $expected = str_replace('<section class="pos"', '<section class="pos ui-pos-module"', $old);
        $expected = str_replace("            @if(\$saleId && in_array('sales.close'", "            <div class=\"ui-pos-financial ui-legacy-light\">\n            @if(\$saleId && in_array('sales.close'", $expected);
        $expected = str_replace('            <div class="pos-future pos-spaced">', "            </div>\n            <div class=\"pos-future pos-spaced\">", $expected);
        $expected = str_replace('$nextTick(() => $refs.panelHeading?.focus())', '$nextTick(() => setTimeout(() => $refs.panelHeading?.focus(), 30))', $expected);
        $this->assertSame($expected, $new);
    }

    private function contract(string $html): array
    {
        // El orden de foco es una diferencia visual explícita, cubierta por la prueba de fuente y QA de teclado.
        $html = str_replace('$nextTick(() => setTimeout(() => $refs.panelHeading?.focus(), 30))', '$nextTick(() => $refs.panelHeading?.focus())', $html);
        $document = new DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET);
        $dom = new DOMXPath($document);
        foreach ($dom->query('//style') as $node) {
            $node->parentNode->removeChild($node);
        }
        foreach ($dom->query('//div[contains(concat(" ", @class, " "), " ui-pos-financial ")]') as $node) {
            while ($node->firstChild) {
                $node->parentNode->insertBefore($node->firstChild, $node);
            }
            $node->parentNode->removeChild($node);
        }
        $elements = [];
        foreach ($dom->query('//body//*') as $node) {
            $attributes = [];
            foreach ($node->attributes as $attribute) {
                if ($attribute->name !== 'class') {
                    $attributes[$attribute->name] = $attribute->value;
                }
            }
            ksort($attributes);
            $elements[] = [$node->nodeName, $attributes];
        }
        $text = [];
        foreach ($dom->query('//body//text()') as $node) {
            $value = trim(preg_replace('/\s+/u', ' ', $node->textContent));
            if ($value !== '') {
                $text[] = $value;
            }
        }

        return compact('elements', 'text');
    }
}
