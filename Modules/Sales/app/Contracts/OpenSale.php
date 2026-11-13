<?php

namespace Modules\Sales\Contracts;

use Modules\Sales\Domain\SaleType;

final readonly class OpenSale
{
    public function __construct(public SaleType $type, public ?string $tableId = null, public ?string $note = null) {}
}
