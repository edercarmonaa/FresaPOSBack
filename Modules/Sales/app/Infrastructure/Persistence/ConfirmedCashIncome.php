<?php

namespace Modules\Sales\Infrastructure\Persistence;

use Illuminate\Support\Facades\DB;
use Modules\Cash\Contracts\ConfirmedSalesCash;
use Modules\Core\Contracts\DecimalAmount;

final class ConfirmedCashIncome implements ConfirmedSalesCash
{
    public function total(string $sessionId): string
    {
        if (DB::connection()->transactionLevel() < 1) {
            throw new \LogicException('El ingreso confirmado requiere transacción externa.');
        }
        $row = DB::table('sales_payments as p')->join('sales_sales as s', 's.id', '=', 'p.sale_id')
            ->where('p.cash_session_id', $sessionId)->where('p.status', 'confirmed')->where('p.method_code', 'cash')
            ->where('s.status', 'closed')->whereNotNull('p.confirmed_at')->selectRaw('COALESCE(SUM(p.applied_amount),0) AS amount')->first();
        $amount = (string) $row->amount;

        return DecimalAmount::from(str_starts_with($amount, '.') ? '0'.$amount : $amount)->value;
    }
}
