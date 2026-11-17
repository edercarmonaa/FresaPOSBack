<?php

namespace Modules\Cash\Domain;

use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Core\Contracts\DecimalAmount;
use Normalizer;

final class MovementInput
{
    public static function amount(mixed $value): string
    {
        try {
            $amount = OpeningFund::normalize($value);
        } catch (ValidationException) {
            throw ValidationException::withMessages(['amount' => 'Usa un importe decimal positivo, hasta 15 enteros y cuatro decimales.']);
        }
        if ($amount === '0.0000') {
            throw ValidationException::withMessages(['amount' => 'El importe debe ser mayor que cero.']);
        }

        return DecimalAmount::from($amount)->value;
    }

    public static function concept(mixed $value): string
    {
        if (! is_string($value) || ! mb_check_encoding($value, 'UTF-8') || preg_match('/[\\p{Cc}\\p{Cf}<>]/u', $value)) {
            throw ValidationException::withMessages(['concept' => 'Escribe una justificación en texto plano.']);
        }
        $value = Normalizer::normalize($value, Normalizer::FORM_C);
        $value = is_string($value) ? Str::squish($value) : '';
        if (preg_match('/[\\p{L}\\p{N}]/u', $value) !== 1 || strlen(mb_convert_encoding($value, 'UTF-16LE', 'UTF-8')) > 400) {
            throw ValidationException::withMessages(['concept' => 'El concepto es obligatorio y admite hasta 200 unidades Unicode.']);
        }

        return $value;
    }
}
