<?php

namespace Tests\Fixtures\UI07D;

use Modules\Reports\Contracts\ReportData;
use Tests\Fixtures\UI05\ReportFixture;

final class FixtureData
{
    public static function make(string $report = 'summary', string $state = 'list'): ReportData
    {
        $total = ['empty' => 0, 'pdf-limit' => 300, 'pdf-overflow' => 301, 'xlsx-limit' => 1000, 'xlsx-overflow' => 1001, 'middle' => 75, 'last' => 75][$state] ?? 2;
        $base = ReportFixture::make($report, $total);
        $items = $base->items;
        if ($items !== []) {
            foreach (['employee', 'product_name', 'observation', 'concept'] as $key) {
                if (array_key_exists($key, $items[0])) {
                    $items[0][$key] = 'Dato ficticio UI-07D <script>alert("escaped")</script>';
                }
            }
            if ($report === 'sales') {
                $items[0]['sale_id'] = '01ARZ3NDEKTSV4RRFFQ69G5FAV';
            }
            if ($report === 'cash-sessions') {
                $items[0]['status'] = 'open';
                foreach (['closed_at', 'closed_employee', 'expected_cash', 'counted_cash', 'difference'] as $key) {
                    $items[0][$key] = null;
                }
            }
        }
        $totals = $base->totals;
        if ($state === 'empty' && array_key_exists('average_ticket', $totals)) {
            $totals['average_ticket'] = null;
        }
        $page = ['middle' => 2, 'last' => 3][$state] ?? 1;
        $filters = $base->filters;
        if ($state === 'preset') {
            $filters['range'] = 'today';
        }

        return new ReportData($base->report, $base->title, $base->generatedAt, $base->timezone, $filters, $base->columns,
            $items, $totals, $base->total, $page, $base->perPage,
            ['Fixture UI-07D: datos ficticios para revisión visual, sin operaciones reales.'], $base->isolation, $base->series);
    }
}
