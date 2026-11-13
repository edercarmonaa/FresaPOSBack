<?php

namespace Modules\Sales\Domain;

use Illuminate\Support\Str;
use InvalidArgumentException;
use Normalizer;

final class SalesText
{
    public static function normalize(?string $value, int $maximum, bool $required = false): ?string
    {
        if ($value === null) {
            if ($required) {
                throw new InvalidArgumentException('El texto es obligatorio.');
            }

            return null;
        }
        if (! mb_check_encoding($value, 'UTF-8') || preg_match('/[\p{Cc}\p{Cf}<>]/u', $value)) {
            throw new InvalidArgumentException('Utiliza texto plano sin HTML ni caracteres de control.');
        }
        $normalized = Normalizer::normalize($value, Normalizer::FORM_C);
        if ($normalized === false) {
            throw new InvalidArgumentException('Texto Unicode inválido.');
        }
        $normalized = Str::squish($normalized);
        if (($required && preg_match('/[\p{L}\p{N}]/u', $normalized) !== 1)
            || strlen(mb_convert_encoding($normalized, 'UTF-16LE', 'UTF-8')) > $maximum * 2) {
            throw new InvalidArgumentException('Texto obligatorio o superior al límite de '.$maximum.' unidades Unicode.');
        }

        return $normalized === '' ? null : $normalized;
    }

    public static function id(string $id): string
    {
        if (! Str::isUlid($id)) {
            throw new InvalidArgumentException('El identificador debe ser un ULID.');
        }

        return strtolower($id);
    }

    public static function quantity(mixed $value, int $maximum): int
    {
        if ($maximum < 1 || $maximum > 2147483647 || ! is_int($value) || $value < 1 || $value > $maximum) {
            throw new InvalidArgumentException('La cantidad debe ser entera, positiva y no superar '.$maximum.'.');
        }

        return $value;
    }
}
