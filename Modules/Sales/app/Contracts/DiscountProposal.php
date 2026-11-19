<?php

namespace Modules\Sales\Contracts;

use InvalidArgumentException;
use Modules\Core\Contracts\DecimalAmount;

/** Solicitud de un único descuento. No concede autorización. */
final readonly class DiscountProposal
{
    public mixed $value;

    public function __construct(public string $type, mixed $value)
    {
        if ($type === 'fixed' && is_string($value)) {
            $value = DecimalAmount::parse($value)->value;
            if ($value === '0.0000') {
                throw new InvalidArgumentException('El descuento fijo debe ser positivo.');
            }
        } elseif ($type !== 'percent' || ! is_int($value) || $value < 1 || $value > 100) {
            throw new InvalidArgumentException('Usa importe fijo exacto o porcentaje entero de 1 a 100.');
        }
        $this->value = $value;
    }
}
