<?php

namespace Modules\Sales\Contracts;

final readonly class ConfirmedSaleDocument
{
    public function __construct(public SaleSnapshot $snapshot, public string $revision, public ?array $correction) {}
}
