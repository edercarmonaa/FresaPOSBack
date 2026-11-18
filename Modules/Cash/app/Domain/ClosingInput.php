<?php

namespace Modules\Cash\Domain;

use Illuminate\Validation\ValidationException;
use Modules\Core\Contracts\DecimalAmount;

final class ClosingInput
{
    public static function counted(mixed $value): string
    {
        try {
            return DecimalAmount::parse($value)->value;
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['counted_cash' => $e->getMessage()]);
        }
    }

    public static function observation(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        try {
            return MovementInput::concept($value);
        } catch (ValidationException) {
            throw ValidationException::withMessages(['observation' => 'Usa una justificación breve en texto plano, de hasta 200 caracteres.']);
        }
    }

    /** Diferencia decimal con signo sin convertir a float o a entero escalado. */
    public static function difference(string $counted, string $expected): string
    {
        $a = DecimalAmount::from($counted);
        $b = DecimalAmount::from($expected);
        try {
            return $a->subtract($b)->value;
        } catch (\InvalidArgumentException) {
            return '-'.$b->subtract($a)->value;
        }
    }
}
