<?php

namespace Modules\Sales\Infrastructure\Persistence;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Cash\Contracts\SessionSales;
use Modules\Core\Contracts\DecimalAmount;

final class SessionSalesSummary implements SessionSales
{
    private function transaction(): void
    {
        if (DB::connection()->transactionLevel() < 1) {
            throw new \LogicException('El resumen Sales exige la transacción externa de Cash.');
        }
    }

    private function decimal(string $value): string
    {
        return DecimalAmount::from(str_starts_with($value, '.') ? '0'.$value : $value)->value;
    }

    public function pending(string $sessionId, int $page = 1): array
    {
        $this->transaction();
        $corrections = Schema::hasTable('sales_corrections');
        $query = DB::table('sales_sales')->where('cash_session_id', $sessionId)->where(function ($query) use ($corrections): void {
            $query->whereIn('status', ['open', 'paused']);
            if ($corrections) {
                $query->orWhereExists(fn ($active) => $active->selectRaw('1')->from('sales_corrections')->whereColumn('sale_id', 'sales_sales.id')->whereNull('completed_at'));
            }
        });

        return ['total' => $query->count(), 'items' => $query->select(['id', 'type', 'status', 'table_id'])->orderBy('id')->forPage($page, 25)->get()->map(function ($row): array {
            $item = (array) $row;
            if ($item['status'] === 'closed') {
                $item['status'] = 'correcting';
            }

            return $item;
        })->all()];
    }

    public function confirmed(string $sessionId): array
    {
        $this->transaction();
        $row = DB::table('sales_sales')->where('cash_session_id', $sessionId)->where('status', 'closed')->whereNotNull('folio')
            ->selectRaw('COUNT(*) AS closed_sales, COALESCE(SUM(total),0) AS total_sold')->first();
        $payments = DB::table('sales_payments as p')->join('sales_sales as s', 's.id', '=', 'p.sale_id')
            ->where('p.cash_session_id', $sessionId)->where('s.status', 'closed')->whereNotNull('s.folio')
            ->where('p.status', 'confirmed')->whereNotNull('p.confirmed_at')->groupBy('p.payment_method_id', 'p.method_code')
            ->select(['p.payment_method_id', 'p.method_code'])->selectRaw('SUM(p.applied_amount) AS applied_amount, COUNT(*) AS payment_count')->orderBy('p.method_code')->orderBy('p.payment_method_id')->get();

        return ['closed_sales' => (int) $row->closed_sales, 'total_sold' => $this->decimal($row->total_sold),
            'payment_methods' => $payments->map(fn ($payment) => ['payment_method_id' => $payment->payment_method_id,
                'method_code' => $payment->method_code, 'applied_amount' => $this->decimal($payment->applied_amount), 'payment_count' => (int) $payment->payment_count])->all()];
    }
}
