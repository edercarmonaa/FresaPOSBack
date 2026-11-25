<?php

namespace Modules\Reports\Application;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Modules\Cash\Contracts\CashReports;
use Modules\Core\Contracts\ReportSlice;
use Modules\Reports\Contracts\OperationalReports;
use Modules\Reports\Contracts\ReportData;
use Modules\Reports\Domain\ReportCriteria;
use Modules\Reports\Domain\ReportDefinition;
use Modules\Reports\Infrastructure\ReadReportSnapshot;
use Modules\Sales\Contracts\SalesReports;
use Modules\Users\Contracts\IdentityAccess;
use Modules\Users\Contracts\IdentityLabels;

final class QueryOperationalReports implements OperationalReports
{
    public function __construct(private readonly ReadReportSnapshot $snapshots, private readonly SalesReports $sales,
        private readonly CashReports $cash, private readonly IdentityAccess $identities, private readonly IdentityLabels $labels) {}

    public function authorize(string $actorId, bool $export = false, string $report = 'summary'): void
    {
        $actor = $this->identities->inspect($actorId);
        $permissions = ['reports.view', 'cash.administrative.execute', ...($export ? ['reports.export'] : [])];
        if (! str_starts_with($report, 'cash-')) {
            $permissions[] = 'sales.view';
        }
        if (str_starts_with($report, 'cash-') || $report === 'summary') {
            $permissions[] = 'cash.view';
            if ($report !== 'cash-movements') {
                $permissions[] = 'cash.closings.view';
            }
        }
        if ($report === 'corrections') {
            array_push($permissions, 'sales.reopen', 'sales.special.authorize');
        }
        if (! $actor || ! $actor->isActive || $actor->mustChangePassword || array_diff($permissions, $actor->permissions) !== []) {
            throw new AuthorizationException;
        }
    }

    public function read(string $actorId, string $report, array $filters = [], ?Closure $access = null, bool $export = false): ReportData
    {
        $this->authorize($actorId, $export, $report);
        $criteria = ReportCriteria::from($report, $filters, $export);

        return $this->snapshots->run(function (string $isolation) use ($actorId, $report, $criteria, $access, $export): ReportData {
            $access?->__invoke();
            $this->authorize($actorId, $export, $report);
            // Cash primero mantiene el orden general de lectura frente a checkout/correcciones.
            $closing = $report === 'summary' ? $this->cash->read($actorId, 'summary', $criteria->query) : null;
            $slice = str_starts_with($report, 'cash-') ? $this->cash->read($actorId, $report, $criteria->query) : $this->sales->read($actorId, $report, $criteria->query);
            if ($export && $slice->total > 1000) {
                throw ValidationException::withMessages(['export' => 'La exportación admite hasta 1000 filas. Reduce el rango o aplica filtros.']);
            }
            $columns = ReportDefinition::columns($report);
            $items = $this->identities($slice);
            foreach ($items as &$item) {
                foreach ($columns as $key => [$label,$type]) {
                    if (! isset($item[$key])) {
                        continue;
                    }
                    if ($type === 'datetime') {
                        $date = CarbonImmutable::parse((string) $item[$key], 'UTC');
                        $item[$key.'_utc'] = $date->utc()->toISOString();
                        $item[$key] = $date->setTimezone($criteria->query->timezone)->toIso8601String();
                    } elseif ($type === 'date') {
                        $item[$key] = substr((string) $item[$key], 0, 10);
                    } elseif ($type === 'integer') {
                        $item[$key] = (int) $item[$key];
                    } elseif (in_array($type, ['money', 'decimal'], true)) {
                        $item[$key] = self::decimal($item[$key]);
                    }
                }
                foreach (['created_at', 'closed_at'] as $key) {
                    if (isset($item[$key]) && ! isset($columns[$key])) {
                        $item[$key] = CarbonImmutable::parse((string) $item[$key], 'UTC')->utc()->toISOString();
                    }
                }
            }unset($item);
            $totals = array_merge($slice->totals, $closing->totals ?? []);
            $totals = array_map(self::decimal(...), $totals);
            $series = array_map(function (array $row): array {
                $row['income'] = self::decimal($row['income']);
                $row['discounts'] = self::decimal($row['discounts']);
                $row['paid_sales'] = (int) $row['paid_sales'];

                return $row;
            }, $slice->series);
            foreach (['paid_sales', 'discounted_sales', 'zero_sales', 'counter_sales', 'table_sales', 'units_sold', 'movements', 'sessions', 'closed_sessions', 'open_sessions', 'closed_cash_sessions', 'corrections', 'confirmed_corrections'] as $key) {
                if (isset($totals[$key])) {
                    $totals[$key] = (int) $totals[$key];
                }
            }
            $notes = $slice->notes;
            if ($report === 'summary') {
                $notes[] = 'Los cierres Cash se seleccionan por fecha de cierre, independientemente de la cohorte de ventas y de sus filtros de producto, método o descuento.';
            }

            return new ReportData($report, ReportDefinition::NAMES[$report], CarbonImmutable::now('UTC')->toISOString(), $criteria->query->timezone,
                array_diff_key($criteria->values, array_flip(['page', 'per_page'])), $columns, $items, $totals, $slice->total,
                $export ? 1 : $criteria->query->page, $export ? max(1, $slice->total) : $criteria->query->perPage, $notes, $isolation, $series);
        });
    }

    /** PDO_SQLSRV puede omitir el cero anterior al punto en decimal(38,4). */
    private static function decimal(mixed $value): mixed
    {
        return is_string($value) ? preg_replace('/\A(-?)\.(?=[0-9]{4}\z)/', '${1}0.', $value) : $value;
    }

    private function identities(ReportSlice $slice): array
    {
        $ids = [];
        foreach ($slice->items as $row) {
            foreach (['employee_id', 'authorizer_id', 'opened_employee_id', 'closed_employee_id'] as $field) {
                if (isset($row[$field])) {
                    $ids[] = $row[$field];
                }
            }
        }
        $names = [];
        foreach (array_chunk(array_values(array_unique($ids)), 500) as $chunk) {
            $names += $this->labels->names($chunk);
        }
        $rows = [];
        foreach ($slice->items as $row) {
            foreach (['employee', 'authorizer', 'opened_employee', 'closed_employee'] as $field) {
                $row[$field] = isset($row[$field.'_id']) ? ($names[$row[$field.'_id']] ?? null) : null;
            }
            foreach (['corrected', 'active_correction'] as $field) {
                if (isset($row[$field])) {
                    $row[$field] = (bool) $row[$field];
                }
            }
            $rows[] = $row;
        }

        return $rows;
    }
}
