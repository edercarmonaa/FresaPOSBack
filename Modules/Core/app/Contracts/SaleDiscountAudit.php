<?php

namespace Modules\Core\Contracts;

use Illuminate\Support\Str;

/** Evidencia cerrada de autorización y de los importes antes/después. */
final readonly class SaleDiscountAudit
{
    public function __construct(public ?array $before, public ?array $after, public string $subtotalBefore, public string $subtotalAfter,
        public string $amountBefore, public string $amountAfter, public string $totalBefore, public string $totalAfter)
    {
        foreach ([$before, $after] as $snapshot) {
            if ($snapshot === null) {
                continue;
            }
            if (count($snapshot) !== 4 || array_diff(['type', 'value', 'authorized_by', 'authorized_at'], array_keys($snapshot)) !== []
                || ! is_string($snapshot['authorized_by']) || ! Str::isUlid($snapshot['authorized_by'])
                || ! is_string($snapshot['authorized_at']) || preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?Z\z/', $snapshot['authorized_at']) !== 1) {
                throw new \InvalidArgumentException('Evidencia de descuento inválida.');
            }
            if ($snapshot['type'] === 'percent') {
                if (! is_int($snapshot['value']) || $snapshot['value'] < 1 || $snapshot['value'] > 100) {
                    throw new \InvalidArgumentException('Porcentaje auditado inválido.');
                }
            } elseif ($snapshot['type'] !== 'fixed' || DecimalAmount::from($snapshot['value'])->value === '0.0000') {
                throw new \InvalidArgumentException('Importe fijo auditado inválido.');
            }
        }
        if (DecimalAmount::from($subtotalBefore)->subtract(DecimalAmount::from($amountBefore))->value !== $totalBefore
            || DecimalAmount::from($subtotalAfter)->subtract(DecimalAmount::from($amountAfter))->value !== $totalAfter) {
            throw new \InvalidArgumentException('Importes auditados incoherentes.');
        }
    }
}
