<?php

namespace Tests\Feature;

use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\UI07C\FixtureData;
use Tests\TestCase;

class CashPresentationTest extends TestCase
{
    public static function states(): array
    {
        $cases = [];
        foreach (['cash-desk' => ['unprovisioned', 'ready', 'inactive', 'read-only', 'open', 'blind', 'schema-missing', 'status', 'error'], 'movements-panel' => ['open', 'pending', 'resolved', 'read-only', 'no-session', 'empty', 'last-page', 'error'], 'closing-panel' => ['idle', 'blind', 'pending', 'blocked', 'balanced', 'shortage', 'surplus', 'no-payments', 'empty', 'last-page', 'read-only', 'error']] as $view => $states) {
            foreach ($states as $state) {
                $cases[$view.' '.$state] = [$view, $state];
            }
        }

        return $cases;
    }

    #[DataProvider('states')]
    public function test_cash_presentation_keeps_controls_text_financial_values_and_permission_states(string $name, string $state): void
    {
        Schema::swap(new class($state !== 'schema-missing')
        {
            public function __construct(private bool $available) {}

            public function hasTable(string $table): bool
            {
                return $this->available && $table === 'cash_reconciliations';
            }
        });
        $data = match ($name) {
            'cash-desk' => FixtureData::desk($state),
            'movements-panel' => FixtureData::movements($state),
            'closing-panel' => FixtureData::closing($state),
        };
        $old = Blade::render(FixtureData::source($name, true), $data);
        $new = Blade::render(FixtureData::source($name), $data);
        $this->assertSame($this->contract($old), $this->contract($new));
        $this->assertStringNotContainsString('<script>alert(', $new);
        if ($name === 'cash-desk' && $state === 'blind') {
            $this->assertStringNotContainsString('100.0000', $new);
            $this->assertStringNotContainsString('data-fixture-child="movements"', $new);
        }
        if ($name === 'closing-panel' && $state === 'blind') {
            $this->assertStringNotContainsString('Efectivo esperado', $new);
            $this->assertStringNotContainsString('data-closing-receipt', $new);
        }
    }

    public function test_operational_script_and_child_component_keys_are_byte_identical(): void
    {
        $old = file_get_contents(base_path('tests/Fixtures/UI07C/cash-desk.blade.php'));
        $new = file_get_contents(base_path('Modules/Cash/resources/views/livewire/cash-desk.blade.php'));
        foreach (['/@script.*?@endscript/s', '/<livewire:[^>]*\/>/'] as $pattern) {
            preg_match_all($pattern, $old, $before);
            preg_match_all($pattern, $new, $after);
            $this->assertNotEmpty($before[0]);
            $this->assertSame($before[0], $after[0]);
        }
    }

    private function contract(string $html): array
    {
        $document = new DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET);
        $dom = new DOMXPath($document);
        foreach ($dom->query('//style') as $node) {
            $node->parentNode->removeChild($node);
        }
        $elements = [];
        foreach ($dom->query('//*') as $node) {
            $attributes = [];
            foreach ($node->attributes as $attribute) {
                if (! in_array($attribute->name, ['class', 'style'], true)) {
                    $attributes[$attribute->name] = in_array($attribute->name, ['disabled', 'required', 'hidden'], true) ? true : $attribute->value;
                }
            }
            ksort($attributes);
            $elements[] = [$node->nodeName, $attributes];
        }
        $text = [];
        foreach ($dom->query('//text()') as $node) {
            $value = trim(preg_replace('/\s+/u', ' ', $node->textContent));
            if ($value !== '') {
                $text[] = $value;
            }
        }

        return compact('elements', 'text');
    }
}
