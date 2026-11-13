<?php

namespace Modules\Sales\Contracts;

final readonly class AddProduct
{
    public function __construct(public string $productId, public mixed $quantity, public ?string $note = null) {}
}
