<?php

namespace Modules\Core\Contracts;

use Illuminate\Support\Str;

final readonly class CashClosingAudit
{
    public function __construct(public string $cashSessionId, public string $registerId, public string $reconciliationId,
        public string $expectedCash, public string $countedCash, public string $difference)
    {
        foreach ([$cashSessionId, $registerId, $reconciliationId] as $id) {
            if (! Str::isUlid($id)) {
                throw new \InvalidArgumentException('Referencia de cierre inválida.');
            }
        }
        $expected = DecimalAmount::from($expectedCash);
        $counted = DecimalAmount::from($countedCash);
        try {
            $computed = $counted->subtract($expected)->value;
        } catch (\InvalidArgumentException) {
            $computed = '-'.$expected->subtract($counted)->value;
        }
        if ($difference !== $computed) {
            throw new \InvalidArgumentException('Diferencia de auditoría incoherente.');
        }
    }
}
