<?php

namespace Modules\Catalog\Application\Ports;

use Modules\Catalog\Application\ImageChange;
use Modules\Catalog\Contracts\ImageReference;

interface ProductImageFiles
{
    /** Registra intención privada antes de escribir; conserva recuperación incluso ante interrupción. */
    public function open(?string $webp, ?ImageReference $previous): ImageChange;

    public function delete(ImageReference $reference): void;

    public function complete(ImageChange $change): void;

    /** @return iterable<ImageChange> */
    public function pending(int $before): iterable;
}
