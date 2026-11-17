<?php

namespace Modules\Sales\Domain;

use InvalidArgumentException;
use Modules\Cash\Contracts\PaymentMethodSnapshot;
use Modules\Core\Contracts\DecimalAmount;
use Modules\Sales\Contracts\PaymentPlan;
use Modules\Sales\Contracts\PreparedPayment;

/** Cálculo sin persistencia ni confirmación financiera. */
final class PaymentAllocation
{
    /** @param list<PaymentMethodSnapshot> $methods */
    public static function calculate(mixed $total, array $methods, array $payments, bool $allowOther = false, bool $requireComplete = true): PaymentPlan
    {
        if (! array_is_list($payments) || count($payments) > 20) {
            throw new InvalidArgumentException('Usa una lista de hasta veinte aplicaciones de pago.');
        }
        $total = DecimalAmount::parse($total);
        $applied = DecimalAmount::from('0.0000');
        $cash = $applied;
        $prepared = [];
        foreach ($payments as $payment) {
            if (! is_array($payment) || array_diff(array_keys($payment), ['payment_method_id', 'applied_amount', 'received_cash', 'reference']) !== []) {
                throw new InvalidArgumentException('Campos de pago no permitidos.');
            }
            $method = null;
            if (! is_string($payment['payment_method_id'] ?? null)) {
                throw new InvalidArgumentException('El método requiere su identificador estable.');
            }
            foreach ($methods as $candidate) {
                if ($candidate->isActive && $candidate->id === strtolower($payment['payment_method_id'])) {
                    $method = $candidate;
                    break;
                }
            }
            if (! $method || ! in_array($method->code, ['cash', 'card', 'transfer', 'other'], true) || $method->code === 'other' && ! $allowOther) {
                throw new InvalidArgumentException('Método de pago no disponible o no autorizado.');
            }
            $amount = DecimalAmount::parse($payment['applied_amount'] ?? null);
            if ($amount->value === '0.0000') {
                throw new InvalidArgumentException('El importe aplicado debe ser positivo.');
            }
            $received = null;
            $change = null;
            if ($method->code === 'cash') {
                $received = DecimalAmount::parse($payment['received_cash'] ?? null);
                $change = $received->subtract($amount);
                $cash = $cash->add($amount);
            } elseif (($payment['received_cash'] ?? null) !== null) {
                throw new InvalidArgumentException('Solo efectivo admite efectivo recibido y cambio.');
            }
            $reference = $payment['reference'] ?? null;
            if ($reference !== null && (! is_string($reference) || preg_match('/\\A[A-Za-z0-9][A-Za-z0-9._\\/-]{0,63}\\z/', $reference) !== 1 || preg_match('/[0-9]{13}/', str_replace(['.', '_', '/', '-'], '', $reference)))) {
                throw new InvalidArgumentException('Usa una referencia corta de comprobante, nunca números de tarjeta o datos bancarios.');
            }
            if (in_array($method->code, ['transfer', 'other'], true) && $reference === null) {
                throw new InvalidArgumentException('El método requiere referencia de comprobante.');
            }
            $applied = $applied->add($amount);
            $total->subtract($applied);
            $prepared[] = new PreparedPayment($method->id, $method->code, $amount->value, $received?->value, $change?->value, $reference);
        }
        $remaining = $total->subtract($applied);
        if ($requireComplete && $remaining->value !== '0.0000') {
            throw new InvalidArgumentException('Los importes aplicados no cubren el total.');
        }

        return new PaymentPlan($total->value, $applied->value, $remaining->value, $cash->value, $prepared);
    }
}
