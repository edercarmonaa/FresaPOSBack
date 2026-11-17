<?php

namespace Modules\Cash\Domain;

use Illuminate\Validation\ValidationException;

final class OpeningFund
{
    public static function normalize(mixed $value): string
    {
        if (! is_string($value) || preg_match('/\A(?:0|[1-9][0-9]{0,14})(?:\.[0-9]{1,4})?\z/', $value) !== 1) {
            throw ValidationException::withMessages(['opening_fund' => 'Usa texto decimal no negativo, hasta 15 enteros y cuatro decimales.']);
        }
        $parts = explode('.', $value, 2);

        return $parts[0].'.'.str_pad($parts[1] ?? '', 4, '0');
    }
}
