<?php

namespace Tests\Integration;

use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Modules\Reports\Application\ExportOperationalReport;
use Modules\Reports\Contracts\OperationalReports;
use Tests\Support\ReceiptsSqlServerTestCase;
use Tests\Support\ReportMeasurements;
use Tests\Support\SqlReportPlan;

class ReportsPerformanceTest extends ReceiptsSqlServerTestCase
{
    public function test_thousand_sale_pagination_totals_query_counts_plans_and_export_limits(): void
    {
        // Escenario sintético reproducible, creado por los servicios reales únicamente en _test.
        for ($i = 0; $i < 1001; $i++) {
            if (in_array($i, [0, 300, 1000], true)) {
                CarbonImmutable::setTestNow(match ($i) {
                    0 => '2026-11-17T18:00:00Z', 300 => '2026-11-18T18:00:00Z', default => '2026-11-19T18:00:00Z'
                });
            }
            $this->settledSale();
        }
        $reports = app(OperationalReports::class);
        $filters = ['from' => '2026-11-17', 'until' => '2026-11-19'];
        $metrics = [];
        foreach ([10, 100] as $size) {
            $this->database->flushQueryLog();
            $this->database->enableQueryLog();
            $start = hrtime(true);
            $data = $reports->read($this->actor, 'sales', $filters + ['per_page' => $size, 'direction' => 'asc']);
            $elapsed = (hrtime(true) - $start) / 1000000;
            $queries = $this->database->getQueryLog();
            $this->database->disableQueryLog();
            $this->assertSame(1001, $data->total);
            $this->assertCount($size, $data->items);
            $this->assertSame('180180.0000', $data->totals['confirmed_income']);
            $this->assertSame(1001, $data->totals['paid_sales']);
            $metrics[] = ['rows' => $size, 'queries' => count($queries), 'elapsed_ms' => round($elapsed, 3), 'peak_memory_bytes' => memory_get_peak_usage(true)];
            foreach ($queries as $query) {
                $this->assertStringNotContainsString('NOLOCK', strtoupper($query['query']));
            }
            $paged = array_values(array_filter($queries, fn ($q) => str_contains(strtolower($q['query']), 'order by') && str_contains($q['query'], '[sales_sales]')))[0];
        }
        $this->assertSame($metrics[0]['queries'], $metrics[1]['queries'], 'El número de consultas no crece por fila.');
        $first = $reports->read($this->actor, 'sales', $filters + ['per_page' => 10, 'page' => 1, 'direction' => 'asc']);
        $second = $reports->read($this->actor, 'sales', $filters + ['per_page' => 10, 'page' => 2, 'direction' => 'asc']);
        $this->assertSame([], array_intersect(array_column($first->items, 'sale_id'), array_column($second->items, 'sale_id')));
        $repeat = $reports->read($this->actor, 'sales', $filters + ['per_page' => 10, 'page' => 1, 'direction' => 'asc']);
        $this->assertSame($first->items, $repeat->items);
        $xml = SqlReportPlan::capture($this->database, $paged['query'], $paged['bindings']);
        $this->assertStringContainsString('ShowPlanXML', $xml);
        file_put_contents(base_path('.tools/reports-sales-plan.xml'), $xml);
        file_put_contents(base_path('.tools/reports-performance.json'), json_encode(['database' => 'fresapos_test', 'synthetic_sales' => 1001, 'metrics' => $metrics, 'plan_captured' => true], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        foreach (['pdf', 'xlsx'] as $format) {
            try {
                app(ExportOperationalReport::class)->generate($this->actor, 'sales', $format, $filters);
                $this->fail('Debe exigir un conjunto menor');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('export', $e->errors());
            }
        }
        $this->assertSame(0, $this->database->table('audit_security_events')->where('event_type', 'reports.export.generated')->count());
        $directory = base_path('.tools/block05-boundaries');
        if (! is_dir($directory)) {
            mkdir($directory);
        }
        $boundaries = [];
        foreach ([['2026-11-16', '2026-11-16', 0], ['2026-11-19', '2026-11-19', 1], ['2026-11-17', '2026-11-17', 300], ['2026-11-17', '2026-11-18', 1000]] as [$from, $until, $rows]) {
            $subset = compact('from', 'until');
            $this->assertSame($rows, $reports->read($this->actor, 'sales', $subset)->total);
            foreach ($rows === 1000 ? ['xlsx'] : ['pdf', 'xlsx'] as $format) {
                $file = app(ExportOperationalReport::class)->generate($this->actor, 'sales', $format, $subset);
                $this->assertStringStartsWith($format === 'pdf' ? '%PDF-' : 'PK', $file->contents);
                file_put_contents($directory.'/sales-'.$rows.'.'.$format, $file->contents);
                $boundaries[] = ['rows' => $rows, 'format' => $format, 'bytes' => strlen($file->contents), 'passed' => true];
            }
        }
        try {
            app(ExportOperationalReport::class)->generate($this->actor, 'sales', 'pdf', ['from' => '2026-11-17', 'until' => '2026-11-18']);
            $this->fail('PDF must reject the full 1000-row set');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('export', $exception->errors());
        }
        file_put_contents($directory.'/results.json', json_encode(['database' => 'fresapos_test', 'real_service_created_sales' => 1001, 'accepted_boundaries' => $boundaries, 'pdf_above_300_rejected' => true, 'xlsx_above_1000_rejected' => true], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        if (getenv('STABILITY_MEASURE') === '1') {
            ReportMeasurements::capture($this->database, $reports, $this->actor, $filters);
        }
    }
}
