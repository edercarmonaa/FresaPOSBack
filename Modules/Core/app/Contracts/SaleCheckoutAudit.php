<?php

namespace Modules\Core\Contracts;

final readonly class SaleCheckoutAudit
{
    public function __construct(public string $cashSessionId, public string $folio, public string $total, public string $cashApplied, public int $paymentCount)
    {
        if (preg_match('/\A[0-7][0-9a-hjkmnp-tv-z]{25}\z/i', $cashSessionId) !== 1 || preg_match('/\A[0-9]{8}[0-9]{4}\z/', $folio) !== 1 || $paymentCount < 0 || $paymentCount > 20) {
            throw new \InvalidArgumentException('Datos de auditoría de cierre inválidos.');
        }
        DecimalAmount::from($total)->subtract(DecimalAmount::from($cashApplied));
    }
}
