<?php

namespace Modules\Sales\Domain;

use Closure;
use Modules\Sales\Contracts\DiscountProposal;

final class DiscountInput
{
    public static function rules(mixed $type): array
    {
        return ['type' => ['required', 'in:percent,fixed'], 'value' => ['required', function (string $attribute, mixed $value, Closure $fail) use ($type): void {
            try {
                if (! is_string($type)) {
                    throw new \InvalidArgumentException('Tipo de descuento inválido.');
                }
                new DiscountProposal($type, $value);
            } catch (\InvalidArgumentException $e) {
                $fail($e->getMessage());
            }
        }]];
    }
}
