<?php

namespace Modules\Sales\Contracts;

use InvalidArgumentException;
use Modules\Sales\Domain\Amount;

/** Solicitud futura de un único descuento. No autoriza ni aplica un descuento. */
final readonly class DiscountProposal
{
    public function __construct(public string $type, public mixed $value)
    {
        if ($type === 'fixed' && is_string($value)) {
            Amount::from($value);
        } elseif ($type !== 'percent' || ! is_int($value) || $value < 1 || $value > 100) {
            throw new InvalidArgumentException('Usa importe fijo exacto o porcentaje entero de 1 a 100.');
        }
    }
}
