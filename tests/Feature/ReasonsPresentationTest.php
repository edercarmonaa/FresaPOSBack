<?php

namespace Tests\Feature;

use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\Blade;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\UI07B\FixtureData;
use Tests\TestCase;

class ReasonsPresentationTest extends TestCase
{
    public static function states(): array
    {
        return array_combine($states = ['list', 'create', 'edit', 'submitted', 'activate', 'deactivate', 'empty', 'last-page', 'error'], array_map(fn ($state) => [$state], $states));
    }

    #[DataProvider('states')]
    public function test_presentation_preserves_text_controls_confirmations_and_disabled_fieldsets(string $state): void
    {
        $data = FixtureData::make($state);
        $before = Blade::render(file_get_contents(base_path('tests/Fixtures/UI07B/reason-administration.blade.php')), $data);
        $after = view('sales::livewire.reason-administration', $data)->render();
        $this->assertSame($this->contract($before), $this->contract($after));
        $this->assertStringNotContainsString('<script>alert(', $after);
    }

    private function contract(string $html): array
    {
        $document = new DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET);
        $dom = new DOMXPath($document);
        foreach ($dom->query('//style') as $node) {
            $node->parentNode->removeChild($node);
        }
        $controls = [];
        foreach ($dom->query('//input | //select | //option | //textarea | //button | //form | //fieldset | //label | //article | //*[@role] | //nav') as $node) {
            $attributes = [];
            foreach ($node->attributes as $attribute) {
                if (! in_array($attribute->name, ['class', 'style'], true)) {
                    $attributes[$attribute->name] = in_array($attribute->name, ['disabled', 'required'], true) ? true : $attribute->value;
                }
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
