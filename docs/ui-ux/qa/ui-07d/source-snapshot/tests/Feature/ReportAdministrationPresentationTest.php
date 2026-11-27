<?php

namespace Tests\Feature;

use DOMDocument;
use DOMXPath;
use Illuminate\Auth\Access\Gate as AccessGate;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Gate;
use Modules\Reports\Domain\ReportDefinition;
use Modules\Users\Infrastructure\Persistence\Models\User;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\UI07D\FixtureData;
use Tests\TestCase;

class ReportAdministrationPresentationTest extends TestCase
{
    public static function reports(): array
    {
        $cases = [];
        foreach (array_keys(ReportDefinition::NAMES) as $report) {
            foreach ([false, true] as $export) {
                $cases[$report.($export ? ' export allowed' : ' export denied')] = [$report, $export];
            }
        }

        return $cases;
    }

    #[DataProvider('reports')]
    public function test_all_report_variants_keep_financial_content_controls_links_and_exact_export_boundaries(string $report, bool $export): void
    {
        Gate::swap(new AccessGate($this->app, fn () => new User));
        Gate::before(fn ($user, string $ability) => $ability === 'reports.export' && $export);
        foreach (['empty', 'list', 'pdf-limit', 'pdf-overflow', 'xlsx-limit', 'xlsx-overflow', 'middle', 'last', 'preset'] as $state) {
            $values = ['data' => FixtureData::make($report, $state), 'reportNames' => ReportDefinition::NAMES];
            $old = Blade::render(file_get_contents(base_path('tests/Fixtures/UI07D/index.blade.php')), $values);
            $new = view('reports::index', $values)->render();
            $this->assertSame($this->contract($old), $this->contract($new), $report.' '.$state);
            $this->assertStringNotContainsString('<script>alert(', $new);
        }
    }

    public function test_error_validation_and_escaped_messages_are_preserved(): void
    {
        foreach ([[], ['from' => ['<img src=x onerror=bad()>'], 'until' => ['Revisa la fecha.']]] as $validation) {
            $values = ['message' => '<script>alert("escaped")</script>', 'validation' => $validation];
            $old = Blade::render(file_get_contents(base_path('tests/Fixtures/UI07D/error.blade.php')), $values);
            $new = view('reports::error', $values)->render();
            $this->assertSame($this->contract($old), $this->contract($new));
            $this->assertStringNotContainsString('<script>alert(', $new);
            $this->assertStringNotContainsString('<img src=x', $new);
        }
    }

    public function test_filter_javascript_is_byte_identical_to_the_start_of_this_stage(): void
    {
        $old = file_get_contents(base_path('tests/Fixtures/UI07D/index.blade.php'));
        $new = file_get_contents(base_path('Modules/Reports/resources/views/index.blade.php'));
        preg_match('/<script>.*?<\/script>/s', $old, $before);
        preg_match('/<script>.*?<\/script>/s', $new, $after);
        $this->assertNotEmpty($before[0]);
        $this->assertSame($before[0], $after[0]);
    }

    private function contract(string $html): array
    {
        $document = new DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET);
        $dom = new DOMXPath($document);
        $elements = [];
        foreach ($dom->query('//main//*') as $node) {
            $attributes = [];
            foreach ($node->attributes as $attribute) {
                if ($attribute->name !== 'class') {
                    $attributes[$attribute->name] = in_array($attribute->name, ['disabled', 'required', 'selected'], true) ? true : $attribute->value;
                }
            }
            ksort($attributes);
            $elements[] = [$node->nodeName, $attributes];
        }
        $text = [];
        foreach ($dom->query('//main//text()') as $node) {
            $value = trim(preg_replace('/\s+/u', ' ', $node->textContent));
            if ($value !== '') {
                $text[] = $value;
            }
        }

        return compact('elements', 'text');
    }
}
