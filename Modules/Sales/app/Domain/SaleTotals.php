<?php

namespace Modules\Sales\Domain;

use Modules\Core\Contracts\DecimalAmount;
use Modules\Sales\Contracts\DiscountProposal;
use Modules\Sales\Contracts\SaleSnapshot;

/** Única fórmula de totales para mutaciones Sales y checkout. */
final class SaleTotals
{
    /** @return array{subtotal:string,discount_amount:string,total:string} */
    public static function calculate(SaleSnapshot $sale, ?DiscountProposal $override = null, bool $remove = false): array
    {
        $subtotal = DecimalAmount::from('0.0000');
        foreach ($sale->items as $item) {
            if ($item->removedAt !== null) {
                continue;
            }
            $line = DecimalAmount::from($item->unitPrice)->multiply($item->quantity);
            if ($line->value !== $item->lineTotal) {
                throw new SalesConflict('Los importes históricos de las líneas no son coherentes.');
            }
            $subtotal = $subtotal->add($line);
        }
        $authorization = $sale->sale->discountAuthorization;
        $proposal = $override ?? ($authorization === null || $remove ? null : new DiscountProposal($authorization['type'], $authorization['value']));
        $discount = match (true) {
            $remove => DecimalAmount::from('0.0000'),
            $proposal?->type === 'percent' => $subtotal->percentage($proposal->value),
            $proposal?->type === 'fixed' => DecimalAmount::from($proposal->value),
            default => DecimalAmount::from($sale->sale->discount),
        };
        if ($override !== null && $subtotal->value === '0.0000') {
            throw new SalesConflict('Aplicar un descuento requiere un subtotal positivo.');
        }
        try {
            $total = $subtotal->subtract($discount);
        } catch (\InvalidArgumentException) {
            throw new SalesConflict('Modifica o elimina el descuento autorizado antes de reducir el subtotal por debajo de su importe.');
        }

        return ['subtotal' => $subtotal->value, 'discount_amount' => $discount->value, 'total' => $total->value];
    }
}
