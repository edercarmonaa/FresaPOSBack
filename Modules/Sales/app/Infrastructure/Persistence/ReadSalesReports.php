<?php

namespace Modules\Sales\Infrastructure\Persistence;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Modules\Core\Contracts\ReportQuery;
use Modules\Core\Contracts\ReportSlice;
use Modules\Sales\Contracts\SalesReports;
use Modules\Users\Contracts\IdentityAccess;

/** Consultas de lectura propiedad de Sales. Sin otro motor de precios, descuentos o cobros. */
final class ReadSalesReports implements SalesReports
{
    public function __construct(private readonly IdentityAccess $identities) {}

    public function read(string $actorId, string $report, ReportQuery $query): ReportSlice
    {
        $actor = $this->identities->inspect($actorId);
        $required = ['reports.view', 'sales.view', 'cash.administrative.execute', ...($report === 'corrections' ? ['sales.reopen', 'sales.special.authorize'] : [])];
        if (! $actor || ! $actor->isActive || $actor->mustChangePassword || array_diff($required, $actor->permissions) !== []) {
            throw new AuthorizationException;
        }
        if (DB::connection()->transactionLevel() < 1) {
            throw new \LogicException('El reporte requiere una transacción consistente externa.');
        }

        return match ($report) {
            'sales','discounts' => $this->sales($query, $report === 'discounts'),
            'products' => $this->products($query), 'payments' => $this->payments($query),
            'corrections' => $this->corrections($query), 'summary' => $this->summary($query),
            default => throw new \InvalidArgumentException('Reporte Sales no disponible.'),
        };
    }

    private function base(ReportQuery $q, bool $financial = true): Builder
    {
        $b = DB::table('sales_sales as s');
        if ($financial) {
            $b->where('s.status', 'closed')->whereNotNull('s.folio')->where('s.closed_at', '>=', $q->from)->where('s.closed_at', '<', $q->until);
        } else {
            $b->where(function (Builder $window) use ($q): void {
                $window->where(fn (Builder $closed) => $closed->where('s.status', 'closed')->where('s.closed_at', '>=', $q->from)->where('s.closed_at', '<', $q->until))
                    ->orWhere(fn (Builder $open) => $open->where('s.status', '<>', 'closed')->where('s.created_at', '>=', $q->from)->where('s.created_at', '<', $q->until));
            });
        }
        foreach (['sale_type' => 's.type', 'cash_session_id' => 's.cash_session_id', 'discount_type' => 's.discount_type', 'state' => 's.status'] as $key => $column) {
            if (isset($q->filters[$key])) {
                $b->where($column, $q->filters[$key]);
            }
        }
        if (isset($q->filters['employee_id'])) {
            $id = $q->filters['employee_id'];
            $b->where(fn (Builder $where) => $where->where('s.closed_by', $id)->orWhere(fn (Builder $created) => $created->whereNull('s.closed_by')->where('s.created_by', $id)));
        }
        if (isset($q->filters['payment_method'])) {
            $method = $q->filters['payment_method'];
            $b->whereExists(fn (Builder $p) => $p->selectRaw('1')->from('sales_payments as pf')->whereColumn('pf.sale_id', 's.id')->where('pf.status', 'confirmed')->where('pf.method_code', $method));
        }
        if (isset($q->filters['product_id']) || isset($q->filters['category_id'])) {
            $b->whereExists(function (Builder $items) use ($q): void {
                $items->selectRaw('1')->from('sales_items as fi')->whereColumn('fi.sale_id', 's.id')->whereNull('fi.removed_at');
                foreach (['product_id', 'category_id'] as $field) {
                    if (isset($q->filters[$field])) {
                        $items->where('fi.'.$field, $q->filters[$field]);
                    }
                }
            });
        }

        return $b;
    }

    private function totals(Builder $base): array
    {
        $b = (clone $base)->where('s.status', 'closed')->whereNotNull('s.folio');
        $row = $b->selectRaw("COUNT_BIG(*) AS paid_sales,
            CAST(COALESCE(SUM(s.subtotal),0) AS decimal(38,4)) AS subtotal_total,
            CAST(COALESCE(SUM(s.total),0) AS decimal(38,4)) AS confirmed_income,
            CAST(COALESCE(SUM(s.discount_amount),0) AS decimal(38,4)) AS discounts_total,
            CAST(AVG(s.total) AS decimal(38,4)) AS average_ticket,
            CAST(AVG(CASE WHEN s.discount_amount>0 THEN s.discount_amount END) AS decimal(38,4)) AS average_discount,
            COALESCE(SUM(CASE WHEN s.discount_amount>0 THEN CAST(1 AS bigint) ELSE 0 END),0) AS discounted_sales,
            COALESCE(SUM(CASE WHEN s.total=0 THEN CAST(1 AS bigint) ELSE 0 END),0) AS zero_sales,
            COALESCE(SUM(CASE WHEN s.type='counter' THEN CAST(1 AS bigint) ELSE 0 END),0) AS counter_sales,
            COALESCE(SUM(CASE WHEN s.type='table' THEN CAST(1 AS bigint) ELSE 0 END),0) AS table_sales")->first();

        return (array) $row;
    }

    private function saleColumns(Builder $base): Builder
    {
        return $base->leftJoin('sales_tables as t', 's.table_id', '=', 't.id')->selectRaw("s.id AS sale_id,s.folio,COALESCE(s.closed_at,s.created_at) AS occurred_at,s.closed_at,s.created_at,s.type,s.status,s.table_id,t.name AS table_name,
            COALESCE(s.closed_by,s.created_by) AS employee_id,s.cash_session_id,s.subtotal,s.discount_amount,s.total,s.discount_type,s.discount_value,s.discount_authorized_by AS authorizer_id,
            CASE WHEN EXISTS(SELECT 1 FROM sales_corrections c WHERE c.sale_id=s.id AND c.status='confirmed') THEN 1 ELSE 0 END AS corrected,
            CASE WHEN EXISTS(SELECT 1 FROM sales_corrections c WHERE c.sale_id=s.id AND c.completed_at IS NULL) THEN 1 ELSE 0 END AS active_correction");
    }

    private function sales(ReportQuery $q, bool $discounts): ReportSlice
    {
        $base = $this->base($q, $discounts);
        if ($discounts) {
            $base->where('s.discount_amount', '>', '0.0000');
        }
        $totals = $this->totals($base);

        return $this->page($this->saleColumns($base), $q, ['occurred_at', 'total', 'folio'], ['sale_id'], $totals,
            ['Los ingresos y el promedio incluyen solo ventas pagadas, también las de total cero.',
                'Se usa la fecha del primer cierre y el total confirmado vigente. Las correcciones actualizan los importes de ese día. La mesa muestra su nombre actual.',
                'El filtro de método selecciona ventas que usaron ese método e incluye todos sus componentes de pago mixto.']);
    }

    private function itemBase(ReportQuery $q): Builder
    {
        $base = $this->base($q)->join('sales_items as i', 'i.sale_id', '=', 's.id')->whereNull('i.removed_at');
        foreach (['product_id', 'category_id'] as $field) {
            if (isset($q->filters[$field])) {
                $base->where('i.'.$field, $q->filters[$field]);
            }
        }

        return $base;
    }

    private function products(ReportQuery $q): ReportSlice
    {
        $base = $this->itemBase($q);
        $totals = (array) (clone $base)->selectRaw('COALESCE(SUM(CAST(i.quantity AS bigint)),0) AS units_sold,CAST(COALESCE(SUM(i.line_total),0) AS decimal(38,4)) AS gross_items_amount')->first();
        $group = $base->selectRaw('i.product_id,i.product_name COLLATE Latin1_General_100_BIN2 AS product_name,i.category_id,i.category_name COLLATE Latin1_General_100_BIN2 AS category_name,SUM(CAST(i.quantity AS bigint)) AS quantity,SUM(i.line_total) AS amount')
            ->groupBy('i.product_id', 'i.category_id')->groupByRaw('i.product_name COLLATE Latin1_General_100_BIN2,i.category_name COLLATE Latin1_General_100_BIN2');
        $rank = DB::query()->fromSub($group, 'g')->select('g.*')->selectRaw('CAST((CAST(amount AS decimal(28,4))*CAST(100 AS decimal(3,0)))/NULLIF(CAST(SUM(amount) OVER() AS decimal(28,4)),0) AS decimal(9,4)) AS share');

        return $this->page($rank, $q, ['quantity', 'amount', 'product_name'], ['product_id', 'category_id', 'product_name', 'category_name'], $totals,
            ['Importe bruto de los productos, antes del descuento aplicado a la venta completa.',
                'Los nombres y categorías históricos se conservan por separado. La participación usa el importe bruto del conjunto filtrado.']);
    }

    private function paymentBase(ReportQuery $q): Builder
    {
        return $this->base($q)->join('sales_payments as p', 'p.sale_id', '=', 's.id')->where('p.status', 'confirmed');
    }

    private function payments(ReportQuery $q): ReportSlice
    {
        $base = $this->paymentBase($q);
        $totals = $this->totals($this->base($q));
        $totals['payments_total'] = (string) (clone $base)->selectRaw('CAST(COALESCE(SUM(p.applied_amount),0) AS decimal(38,4)) AS value')->first()->value;
        $income = $this->base($q)->selectRaw('s.total AS amount');
        $negative = (clone $base)->selectRaw('-p.applied_amount AS amount');
        $totals['reconciliation_difference'] = (string) DB::query()->fromSub($income->unionAll($negative), 'reconciliation')->selectRaw('CAST(COALESCE(SUM(amount),0) AS decimal(38,4)) AS value')->first()->value;
        $group = $base->selectRaw('p.method_code AS method,COUNT_BIG(*) AS operations,SUM(p.applied_amount) AS amount')->groupBy('p.method_code');
        $rows = DB::query()->fromSub($group, 'g')->select('g.*')->selectRaw('CAST((CAST(amount AS decimal(28,4))*CAST(100 AS decimal(3,0)))/NULLIF(CAST(SUM(amount) OVER() AS decimal(28,4)),0) AS decimal(9,4)) AS share');

        return $this->page($rows, $q, ['amount', 'operations', 'method'], ['method'], $totals,
            ['Se suman importes aplicados confirmados, nunca efectivo recibido bruto ni cambio.',
                'El método selecciona ventas y conserva sus pagos mixtos para conciliar. Una diferencia distinta de cero requiere revisión.']);
    }

    private function summary(ReportQuery $q): ReportSlice
    {
        $base = $this->base($q);
        $totals = $this->totals($base);
        $totals['units_sold'] = (int) $this->itemBase($q)->selectRaw('COALESCE(SUM(CAST(i.quantity AS bigint)),0) AS quantity')->first()->quantity;
        foreach ($this->paymentBase($q)->selectRaw('p.method_code,SUM(p.applied_amount) AS amount')->groupBy('p.method_code')->get() as $payment) {
            $totals[$payment->method_code.'_applied'] = (string) $payment->amount;
        }
        foreach (['cash', 'card', 'transfer', 'other'] as $method) {
            $totals[$method.'_applied'] ??= '0.0000';
        }
        $zone = match ($q->timezone) {
            'America/Mexico_City' => 'Central Standard Time (Mexico)','UTC' => 'UTC',default => throw new \InvalidArgumentException('Zona SQL no configurada para la serie diaria.')
        };
        $daily = $base->selectRaw("CONVERT(date,s.closed_at AT TIME ZONE 'UTC' AT TIME ZONE ?) AS business_date,s.total,s.discount_amount", [$zone]);
        $group = DB::query()->fromSub($daily, 'd')->selectRaw('business_date,COUNT_BIG(*) AS paid_sales,CAST(SUM(total) AS decimal(38,4)) AS income,CAST(SUM(discount_amount) AS decimal(38,4)) AS discounts')->groupBy('business_date');

        $page = $this->page($group, $q, ['business_date', 'income', 'paid_sales'], ['business_date'], $totals,
            ['Ingreso: total confirmado de ventas cuyo primer cierre pertenece al rango. Promedio: ingreso entre ventas pagadas, incluidas las de total cero.',
                'Productos vendidos: unidades vigentes confirmadas. Efectivo: importe aplicado neto; entradas manuales, fondo y reimpresiones no son ventas.']);

        return new ReportSlice($page->items, $page->total, $page->totals, $page->notes, (clone $group)->orderBy('business_date')->get()->map(fn ($row) => (array) $row)->all());
    }

    private function corrections(ReportQuery $q): ReportSlice
    {
        $base = DB::table('sales_corrections as c')->join('sales_sales as s', 's.id', '=', 'c.sale_id')->where(function (Builder $dates) use ($q): void {
            $dates->where(fn (Builder $done) => $done->whereNotNull('c.completed_at')->where('c.completed_at', '>=', $q->from)->where('c.completed_at', '<', $q->until))
                ->orWhere(fn (Builder $active) => $active->whereNull('c.completed_at')->where('c.reopened_at', '>=', $q->from)->where('c.reopened_at', '<', $q->until));
        });
        foreach (['cash_session_id' => 'c.cash_session_id', 'state' => 'c.status'] as $key => $column) {
            if (isset($q->filters[$key])) {
                $base->where($column, $q->filters[$key]);
            }
        }
        if (isset($q->filters['employee_id'])) {
            $base->whereRaw('COALESCE(c.confirmed_by,c.reopened_by)=?', [$q->filters['employee_id']]);
        }
        $totals = (array) (clone $base)->selectRaw("COUNT_BIG(*) AS corrections,COALESCE(SUM(CASE WHEN c.status='confirmed' THEN CAST(1 AS bigint) ELSE 0 END),0) AS confirmed_corrections,CAST(COALESCE(SUM(CASE WHEN c.status='confirmed' THEN c.additional_paid ELSE 0 END),0) AS decimal(38,4)) AS additional_collected")->first();
        $rows = $base->selectRaw("c.id AS correction_id,s.id AS sale_id,s.folio,s.closed_at AS original_at,COALESCE(c.completed_at,c.reopened_at) AS occurred_at,c.reopened_by AS authorizer_id,c.confirmed_by AS employee_id,c.status,c.observation,
            JSON_VALUE(c.original_snapshot,'$.sale.total') AS before_total,
            CASE WHEN c.status IN ('confirmed','unchanged') THEN JSON_VALUE(c.result_snapshot,'$.sale.total') END AS after_total,
            CASE WHEN c.status IN ('confirmed','unchanged') THEN c.additional_paid END AS difference");

        return $this->page($rows, $q, ['occurred_at', 'folio'], ['correction_id'], $totals,
            ['Fecha de corrección: finalización, o reapertura si sigue activa. La fecha original se presenta separada.',
                'Las activas no muestran importes provisionales. Las descartadas no suman cambios financieros. El cobro adicional confirmado pertenece a la venta original.']);
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
