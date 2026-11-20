<?php

namespace Modules\Core\Contracts;

final readonly class SaleCorrectionAudit
{
    public function __construct(public string $correctionId, public string $cashSessionId, public string $folio,
        public array $changes, public string $totalBefore, public string $totalAfter, public string $additionalPaid)
    {
        foreach ([$correctionId, $cashSessionId] as $id) {
            if (preg_match('/\A[0-7][0-9a-hjkmnp-tv-z]{25}\z/i', $id) !== 1) {
                throw new \InvalidArgumentException('Invalid correction reference.');
            }
        }
        if (preg_match('/\A[0-9]{12}\z/', $folio) !== 1 || $changes === [] || ! array_is_list($changes)
            || array_diff($changes, ['items', 'note', 'discount']) !== [] || count($changes) !== count(array_unique($changes))) {
            throw new \InvalidArgumentException('Invalid correction summary.');
        }
        foreach ([$totalBefore, $totalAfter, $additionalPaid] as $amount) {
            DecimalAmount::from($amount);
        }
        if (DecimalAmount::from($totalAfter)->subtract(DecimalAmount::from($totalBefore))->value !== $additionalPaid) {
            throw new \InvalidArgumentException('Inconsistent correction adjustment.');
        }
    }
}
