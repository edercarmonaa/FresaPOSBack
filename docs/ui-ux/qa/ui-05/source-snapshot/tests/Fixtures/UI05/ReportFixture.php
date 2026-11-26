<?php

namespace Tests\Fixtures\UI05;

use Modules\Reports\Contracts\ReportData;
use Modules\Reports\Domain\ReportDefinition;

// Datos ficticios exclusivos de pruebas; no se consultan ni publican datos operativos.
final class ReportFixture
{
    public static function make(string $report = 'summary', int $total = 2): ReportData
    {
        $columns = ReportDefinition::columns($report);
        $item = [];
        foreach ($columns as $key => [$label, $type]) {
            $item[$key] = match ($type) {
                'date' => '2026-11-26', 'datetime' => '2026-11-26T18:00:00Z', 'integer' => 2,
                'money' => '1200.5000', 'decimal' => '50.0000', 'boolean' => false,
                default => str_ends_with($key, '_id') ? '01ARZ3NDEKTSV4RRFFQ69G5FAV' : 'Dato ficticio UI-05',
            };
        }
        $filters = ['range' => 'custom', 'from' => '2026-11-25', 'until' => '2026-11-26', 'sort' => ReportDefinition::sorts($report)[0], 'direction' => 'desc'];
        $totals = match ($report) {
            'summary', 'sales' => ['paid_sales' => 2, 'confirmed_income' => '1200.5000', 'discounts_total' => '0.0000', 'average_ticket' => '600.2500'],
            'products' => ['units_sold' => 2, 'gross_items_amount' => '1200.5000'],
            'payments' => ['payments_total' => '1200.5000', 'reconciliation_difference' => '0.0000'],
            'cash-movements' => ['movements' => 2, 'manual_in' => '1200.5000', 'manual_out' => '0.0000'],
            'cash-sessions' => ['sessions' => 2, 'closed_sessions' => 0, 'closed_expected_cash' => null],
            'cash-reconciliations' => ['closed_cash_sessions' => 2, 'closing_differences' => '-12.5000'],
            'discounts' => ['discounted_sales' => 2, 'discounts_total' => '1200.5000'],
            'corrections' => ['corrections' => 2, 'additional_collected' => '1200.5000'],
        };
        if ($total === 0) {
            $totals = array_map(fn ($value) => $value === null ? null : (is_int($value) ? 0 : '0.0000'), $totals);
        }

        return new ReportData($report, ReportDefinition::NAMES[$report], '2026-11-26T18:00:00Z', 'America/Mexico_City',
            $filters, $columns, $total === 0 ? [] : [$item], $totals, $total, 1, 25,
            ['Fixture de QA: sin operaciones reales. Se conservan precisión y datos recibidos.'], 'READ COMMITTED',
            $report === 'summary' && $total > 0 ? [['business_date' => '2026-11-26', 'income' => '1200.5000']] : []);
    }
}
