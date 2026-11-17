<?php

namespace Modules\Sales\Infrastructure\Persistence;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Sales\Contracts\PaymentPlan;
use Modules\Sales\Infrastructure\Persistence\Models\SalePayment;

final class CheckoutStore
{
    public function persist(string $actorId, string $saleId, string $sessionId, PaymentPlan $plan, CarbonImmutable $closedAt): void
    {
        if (DB::connection()->transactionLevel() < 1) {
            throw new \LogicException('Los pagos requieren la transacción de checkout.');
        }
        foreach ($plan->payments as $payment) {
            SalePayment::query()->create(['sale_id' => $saleId, 'cash_session_id' => $sessionId, 'payment_method_id' => $payment->paymentMethodId,
                'method_code' => $payment->methodCode, 'applied_amount' => $payment->appliedAmount, 'received_cash' => $payment->receivedCash,
                'change_cash' => $payment->changeCash, 'reference' => $payment->reference, 'created_by' => $actorId,
                'status' => 'confirmed', 'confirmed_at' => $closedAt]);
        }
    }

    public function payments(string $saleId): array
    {
        return SalePayment::query()->where('sale_id', $saleId)->where('status', 'confirmed')->orderBy('id')->get()->map(fn (SalePayment $row) => [
            'id' => $row->getAttribute('id'), 'sale_id' => $saleId, 'cash_session_id' => $row->getAttribute('cash_session_id'),
            'payment_method_id' => $row->getAttribute('payment_method_id'), 'method_code' => $row->getAttribute('method_code'),
            'applied_amount' => $row->getAttribute('applied_amount'), 'received_cash' => $row->getAttribute('received_cash'),
            'change_cash' => $row->getAttribute('change_cash'), 'reference' => $row->getAttribute('reference'),
            'created_by' => $row->getAttribute('created_by'), 'confirmed_at' => $row->getAttribute('confirmed_at')->utc()->toISOString(), 'status' => 'confirmed',
        ])->all();
    }
}
