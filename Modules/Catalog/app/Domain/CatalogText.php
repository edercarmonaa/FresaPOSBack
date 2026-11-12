<?php

namespace Modules\Catalog\Domain;

use InvalidArgumentException;
use Normalizer;

final class CatalogText
{
    public static function normalize(mixed $value, int $max = 150, bool $required = true): string
    {
        if (! is_string($value) || ! mb_check_encoding($value, 'UTF-8') || preg_match('/[\p{Cc}\p{Cf}]/u', $value)) {
            throw new InvalidArgumentException('Utiliza texto válido sin caracteres de control.');
        }
        $normalized = Normalizer::normalize($value, Normalizer::FORM_C);
        if ($normalized === false) {
            throw new InvalidArgumentException('No fue posible normalizar el texto.');
        }
        $normalized = preg_replace('/\s+/u', ' ', $normalized) ?? '';
        $normalized = trim($normalized);
        if (($required && preg_match('/[\p{L}\p{N}]/u', $normalized) !== 1)
            || strlen(mb_convert_encoding($normalized, 'UTF-16LE', 'UTF-8')) > $max * 2) {
            throw new InvalidArgumentException('El texto es obligatorio y no debe superar '.$max.' unidades Unicode de almacenamiento.');
        }

        return $normalized;
    }

    public static function id(mixed $value): string
    {
        if (! is_string($value) || preg_match('/\A[0-7][0-9A-HJKMNP-TV-Z]{25}\z/i', $value) !== 1) {
            throw new InvalidArgumentException('El identificador debe ser un ULID válido.');
        }

        return strtolower($value);
    }
}
