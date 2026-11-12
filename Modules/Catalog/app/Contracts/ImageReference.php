<?php

namespace Modules\Catalog\Contracts;

use InvalidArgumentException;

final readonly class ImageReference
{
    public function __construct(public string $disk, public string $key)
    {
        if (preg_match('/\A[a-z][a-z0-9_-]{0,31}\z/', $disk) !== 1
            || preg_match('/\Acatalog\/products\/[0-7][0-9a-hjkmnp-tv-z]{25}\.webp\z/', $key) !== 1) {
            throw new InvalidArgumentException('La imagen requiere un disco lógico y una clave relativa catalog/products/ULID.webp.');
        }
    }
}
