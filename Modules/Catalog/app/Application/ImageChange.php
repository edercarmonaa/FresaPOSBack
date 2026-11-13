<?php

namespace Modules\Catalog\Application;

use Modules\Catalog\Contracts\ImageReference;

final readonly class ImageChange
{
    public function __construct(public string $id, public ?ImageReference $next, public ?ImageReference $previous, public int $createdAt) {}
}
