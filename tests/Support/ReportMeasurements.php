<?php

namespace Tests\Support;

use Illuminate\Database\Connection;
use Modules\Reports\Contracts\OperationalReports;
use Modules\Reports\Domain\ReportDefinition;

/** Mediciones adicionales; no cambia datos, cachés del servidor ni umbrales funcionales. */
final class ReportMeasurements
{
    public static function capture(Connection $db, OperationalReports $reports, string $actor, array $filters): void
    {
        TestDatabaseGuard::assertConnected($db);
        $measurements = [];
        foreach (range(0, 9) as $iteration) {
            // Alternar el orden reduce el sesgo sistemático entre tamaños.
            foreach ($iteration % 2 === 0 ? [10, 100] : [100, 10] as $size) {
                $db->flushQueryLog();
                $db->enableQueryLog();
                $start = hrtime(true);
                try {
                    $data = $reports->read($actor, 'sales', $filters + ['per_page' => $size, 'direction' => 'asc']);
                } finally {
                    $elapsed = (hrtime(true) - $start) / 1000000;
                    $queries = $db->getQueryLog();
                    $db->disableQueryLog();
                }
                if ($data->total !== 1001 || count($data->items) !== $size || $data->totals['confirmed_income'] !== '180180.0000') {
                    throw new \RuntimeException('El fixture medido dejó de ser equivalente.');
                }
                $renderStart = hrtime(true);
                $html = view('reports::index', ['data' => $data, 'reportNames' => ReportDefinition::NAMES])->render();
                $renderMs = (hrtime(true) - $renderStart) / 1000000;
                $sqlMs = array_sum(array_column($queries, 'time'));
                $measurements[] = [
                    'iteration' => $iteration, 'rows' => $size, 'queries' => count($queries),
                    'application_ms' => $elapsed, 'reported_sql_ms' => $sqlMs,
                    'non_sql_estimate_ms' => max(0, $elapsed - $sqlMs), 'blade_render_ms' => $renderMs,
                    'application_plus_blade_ms' => $elapsed + $renderMs, 'html_bytes' => strlen($html),
                    'queries_detail' => array_map(function (array $query): array {
                        preg_match_all('/(?:from|join)\s+\[([a-z_]+)\]/i', $query['query'], $tables);

                        return ['fingerprint' => hash('sha256', $query['query']),
                            'tables' => array_values(array_unique($tables[1])), 'duration_ms' => $query['time']];
                    }, $queries),
                ];
            }
        }
        file_put_contents(base_path('.tools/stability-performance.json'), json_encode([
            'captured_at_utc' => gmdate('c'), 'database' => 'fresapos_test', 'synthetic_sales' => 1001,
            'query_log_enabled' => true, 'parallel_test_runners' => 1,
            'cold_sql_cache_tested' => false, 'cold_php_process_tested' => false,
            'notes' => 'Fixture creado previamente por los servicios reales. Primera muestra adicional y repeticiones calientes; sin vaciar caché global. Blade directo, sin red ni navegador. Tiempo no SQL es estimación, no CPU PHP.',
            'measurements' => $measurements,
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }
}
