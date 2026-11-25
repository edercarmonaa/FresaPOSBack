<?php

namespace Modules\Cash\Infrastructure\Persistence;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Modules\Cash\Contracts\CashReports;
use Modules\Core\Contracts\ReportQuery;
use Modules\Core\Contracts\ReportSlice;
use Modules\Users\Contracts\IdentityAccess;

final class ReadCashReports implements CashReports
{
    public function __construct(private readonly IdentityAccess $identities) {}

    public function read(string $actorId, string $report, ReportQuery $q): ReportSlice
    {
        $actor = $this->identities->inspect($actorId);
        $permissions = ['reports.view', 'cash.view', 'cash.administrative.execute', ...($report !== 'cash-movements' ? ['cash.closings.view'] : [])];
        if (! $actor || ! $actor->isActive || $actor->mustChangePassword || array_diff($permissions, $actor->permissions) !== []) {
            throw new AuthorizationException;
        }
        if (DB::connection()->transactionLevel() < 1) {
            throw new \LogicException('El reporte requiere una transacción consistente externa.');
        }

        return match ($report) {
            'cash-movements' => $this->movements($q), 'cash-sessions' => $this->sessions($q),
            'cash-reconciliations' => $this->reconciliations($q), 'summary' => new ReportSlice([], 0, $this->closingTotals($this->closingBase($q))),
            default => throw new \InvalidArgumentException('Reporte Cash no disponible.'),
        };
    }

    private function window(Builder $b, string $date, ReportQuery $q): Builder
    {
        return $b->where($date, '>=', $q->from)->where($date, '<', $q->until);
    }

    private function movements(ReportQuery $q): ReportSlice
    {
        $b = $this->window(DB::table('cash_movements as m'), 'm.occurred_at', $q);
        foreach (['employee_id' => 'm.created_by', 'cash_session_id' => 'm.cash_session_id', 'movement_type' => 'm.type'] as $key => $column) {
            if (isset($q->filters[$key])) {
                $b->where($column, $q->filters[$key]);
            }
        }
        $totals = (array) (clone $b)->selectRaw("COUNT_BIG(*) AS movements,
            CAST(COALESCE(SUM(CASE WHEN m.type='cash_in' THEN m.amount ELSE 0 END),0) AS decimal(38,4)) AS manual_in,
            CAST(COALESCE(SUM(CASE WHEN m.type='cash_out' THEN m.amount ELSE 0 END),0) AS decimal(38,4)) AS manual_out,
            CAST(COALESCE(SUM(CASE WHEN m.type='cash_in' THEN m.amount ELSE -m.amount END),0) AS decimal(38,4)) AS manual_net")->first();
        $rows = $b->selectRaw('m.id AS movement_id,m.occurred_at,m.cash_session_id,m.type,m.concept,m.amount,m.created_by AS employee_id');

        return $this->page($rows, $q, ['occurred_at', 'amount'], ['movement_id'], $totals,
            ['Entradas y salidas manuales separadas de ventas. Importe positivo; la naturaleza se conserva en tipo. Neto = entradas menos salidas.',
                'La referencia disponible es el identificador del movimiento y su concepto registrado; no se inventa otra referencia.']);
    }

    private function sessions(ReportQuery $q): ReportSlice
    {
        $b = $this->window(DB::table('cash_sessions as c'), 'c.opened_at', $q);
        foreach (['employee_id' => 'c.opened_by', 'cash_session_id' => 'c.id', 'state' => 'c.status'] as $key => $column) {
            if (isset($q->filters[$key])) {
                $b->where($column, $q->filters[$key]);
            }
        }
        $totals = (array) (clone $b)->selectRaw("COUNT_BIG(*) AS sessions,COALESCE(SUM(CASE WHEN c.status='closed' THEN CAST(1 AS bigint) ELSE 0 END),0) AS closed_sessions,COALESCE(SUM(CASE WHEN c.status='open' THEN CAST(1 AS bigint) ELSE 0 END),0) AS open_sessions")->first();
        $rows = $b->join('cash_registers as reg', 'reg.id', '=', 'c.register_id')->leftJoin('cash_reconciliations as r', function ($join): void {
            $join->on('r.cash_session_id', '=', 'c.id')->where('c.status', 'closed');
        })
            ->selectRaw("c.id AS cash_session_id,COALESCE(JSON_VALUE(r.summary,'$.register_name'),reg.name) AS register_name,c.opened_at,c.opened_by AS opened_employee_id,c.opening_fund,c.status,c.closed_at,c.closed_by AS closed_employee_id,
                CASE WHEN c.status='closed' THEN r.expected_cash END AS expected_cash,
                CASE WHEN c.status='closed' THEN r.counted_cash END AS counted_cash,
                CASE WHEN c.status='closed' THEN r.difference END AS difference");

        return $this->page($rows, $q, ['opened_at', 'closed_at', 'opening_fund'], ['cash_session_id'], $totals,
            ['Una fila por turno; filtro temporal por apertura y empleado de apertura. No se fusionan sesiones del mismo día.',
                'El esperado, contado y diferencia se muestran después de confirmar el cierre. Permanecen vacíos mientras la sesión esté abierta.']);
    }

    private function closingBase(ReportQuery $q): Builder
    {
        $b = $this->window(DB::table('cash_reconciliations as r')->join('cash_sessions as c', 'c.id', '=', 'r.cash_session_id')->where('c.status', 'closed'), 'r.confirmed_at', $q);
        foreach (['cash_session_id' => 'r.cash_session_id', 'employee_id' => 'r.counted_by'] as $key => $column) {
            if (isset($q->filters[$key])) {
                $b->where($column, $q->filters[$key]);
            }
        }

        return $b;
    }

    private function closingTotals(Builder $b): array
    {
        return (array) (clone $b)->selectRaw('COUNT_BIG(*) AS closed_cash_sessions,CAST(COALESCE(SUM(r.expected_cash),0) AS decimal(38,4)) AS closed_expected_cash,CAST(COALESCE(SUM(r.counted_cash),0) AS decimal(38,4)) AS closed_counted_cash,CAST(COALESCE(SUM(r.difference),0) AS decimal(38,4)) AS closing_differences')->first();
    }

    private function reconciliations(ReportQuery $q): ReportSlice
    {
        $b = $this->closingBase($q);
        $totals = $this->closingTotals($b);
        $rows = $b->selectRaw("r.id AS reconciliation_id,r.cash_session_id,r.confirmed_at AS closed_at,r.counted_by AS closed_employee_id,r.expected_cash,r.counted_cash,r.difference,r.observation,
            JSON_VALUE(r.summary,'$.opening_fund') AS opening_fund,JSON_VALUE(r.summary,'$.cash_in') AS cash_in,
            JSON_VALUE(r.summary,'$.cash_out') AS cash_out,JSON_VALUE(r.summary,'$.confirmed_sales_cash') AS confirmed_sales_cash");

        return $this->page($rows, $q, ['closed_at', 'difference', 'expected_cash'], ['reconciliation_id'], $totals,
            ['Solo cierres confirmados; importes y diferencia de la instantánea inmutable del arqueo, sin recalcular el pasado.',
                'Fecha y empleado corresponden al cierre. El rango puede incluir sesiones abiertas en días distintos; no representa la misma cohorte que ventas por primer cierre.']);
    }

    private function page(Builder $rows, ReportQuery $q, array $sorts, array $ties, array $totals, array $notes): ReportSlice
    {
        if (! in_array($q->sort, $sorts, true) || ! in_array($q->direction, ['asc', 'desc'], true)) {
            throw new \InvalidArgumentException('Orden no permitido.');
        }
        $wrapped = DB::query()->fromSub($rows, 'r');
        $total = (clone $wrapped)->count();
        $wrapped->orderBy($q->sort, $q->direction);
        foreach (array_diff($ties, [$q->sort]) as $tie) {
            $wrapped->orderBy($tie, $q->direction);
        }
        $items = $wrapped->forPage($q->page, $q->perPage)->get()->map(fn ($row) => (array) $row)->all();

        return new ReportSlice($items, $total, $totals, $notes);
    }
}
