<?php

namespace Modules\Catalog\Domain;

use InvalidArgumentException;

final readonly class Price
{
    private function __construct(public string $value) {}

    public static function from(mixed $value): self
    {
        if (is_int($value)) {
            $value = (string) $value;
        }
        if (! is_string($value) || preg_match('/\A[0-9]{1,15}(?:\.[0-9]{1,4})?\z/', trim($value)) !== 1) {
            throw new InvalidArgumentException('El precio debe ser decimal no negativo, con hasta 15 enteros y cuatro decimales; no se admiten floats.');
        }
        $parts = explode('.', trim($value), 2);

        return new self((ltrim($parts[0], '0') ?: '0').'.'.str_pad($parts[1] ?? '', 4, '0'));
    }
}
