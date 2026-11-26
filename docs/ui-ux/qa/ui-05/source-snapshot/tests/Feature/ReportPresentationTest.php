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
use Tests\Fixtures\UI05\ReportFixture;
use Tests\TestCase;

class ReportPresentationTest extends TestCase
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
    public function test_data_filters_links_and_export_limits_match_previous_view(string $report, bool $export): void
    {
        Gate::swap(new AccessGate($this->app, fn () => new User));
        Gate::before(fn ($user, string $ability) => $ability === 'reports.export' && $export);
        foreach ([0, 301, 1001] as $total) {
            $data = ['data' => ReportFixture::make($report, $total), 'reportNames' => ReportDefinition::NAMES];
            $old = Blade::render(file_get_contents(base_path('tests/Fixtures/UI05/index.blade.php')), $data);
            $new = view('reports::index', $data)->render();
            $this->assertSame($this->contract($old), $this->contract($new), $report.' total '.$total);
        }
    }

    public function test_error_messages_are_preserved_and_escaped(): void
    {
        $data = ['message' => '<script>message()</script>', 'validation' => ['range' => ['<img src=x onerror=bad()>']]];
        $old = Blade::render(file_get_contents(base_path('tests/Fixtures/UI05/error.blade.php')), $data);
        $new = view('reports::error', $data)->render();
        $this->assertSame($this->contract($old), $this->contract($new));
        $this->assertStringContainsString('&lt;script&gt;message()&lt;/script&gt;', $new);
        $this->assertStringNotContainsString('<img src=x', $new);
        $this->assertStringContainsString('role="alert"', $new);
    }

    public function test_functional_filter_javascript_is_byte_identical(): void
    {
        $old = file_get_contents(base_path('tests/Fixtures/UI05/index.blade.php'));
        $new = file_get_contents(base_path('Modules/Reports/resources/views/index.blade.php'));
        preg_match('~<script>(.*?)</script>~s', $old, $before);
        preg_match('~<script>(.*?)</script>~s', $new, $after);
        $this->assertNotEmpty($before[1]);
        $this->assertSame($before[1], $after[1]);
    }

    private function contract(string $html): array
    {
        $document = new DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET);
        $dom = new DOMXPath($document);
        $controls = [];
        foreach ($dom->query('//main//form | //main//input | //main//select | //main//option | //main//button | //main//a') as $element) {
            $attributes = [];
            foreach (['action', 'method', 'name', 'type', 'value', 'required', 'maxlength', 'selected', 'disabled', 'href', 'data-report-range', 'data-report-date', 'aria-current'] as $key) {
                if ($element->hasAttribute($key)) {
                    $attributes[$key] = $element->getAttribute($key);
                }
            }
            $controls[] = [$element->nodeName, $attributes];
        }
        $text = [];
        foreach ($dom->query('//main') as $main) {
            // Scripts/styles are implementation details, not the displayed financial content.
            foreach ($dom->query('.//script | .//style', $main) as $node) {
                $node->parentNode->removeChild($node);
            }
            $text[] = trim(preg_replace('/\s+/u', ' ', $main->textContent));
        }
        $chart = [];
        foreach ($dom->query('//main//*[local-name()="svg"]//*[local-name()="rect"]') as $rect) {
            $chart[] = [$rect->getAttribute('width'), $rect->getAttribute('height')];
        }

        return compact('controls', 'text', 'chart');
    }
}
