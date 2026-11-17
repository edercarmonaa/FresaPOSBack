<?php

namespace Modules\Core\Contracts;

/** Datos financieros cerrados, sin concepto libre ni credenciales. */
final readonly class CashMovementAudit
{
    public function __construct(public string $cashSessionId, public string $type, public string $amount)
    {
        if (preg_match('/\\A[0-7][0-9a-hjkmnp-tv-z]{25}\\z/i', $cashSessionId) !== 1 || ! in_array($type, ['cash_in', 'cash_out'], true) || DecimalAmount::from($amount)->value === '0.0000') {
            throw new \InvalidArgumentException('Datos de auditoría financiera inválidos.');
        }
    }
}
