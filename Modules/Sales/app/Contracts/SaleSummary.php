<?php

namespace Modules\Sales\Contracts;

use Modules\Sales\Domain\SaleState;
use Modules\Sales\Domain\SaleType;

final readonly class SaleSummary
{
    public function __construct(
        public string $id, public SaleType $type, public SaleState $state,
        public ?string $tableId, public string $createdBy, public string $cashSessionId,
        public ?string $note, public string $subtotal, public string $discount,
        public string $total, public ?string $folio, public ?string $closedAt,
        public ?string $cancelledAt, public ?string $cancelledBy, public ?string $cancellationReason,
        public string $createdAt, public string $updatedAt,
    ) {}
}
