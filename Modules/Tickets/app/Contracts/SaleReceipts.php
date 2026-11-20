<?php

namespace Modules\Tickets\Contracts;

use Closure;

interface SaleReceipts
{
    public function view(string $actorId, ?string $saleId = null, ?string $folio = null, ?Closure $access = null): ReceiptData;

    public function reprint(string $actorId, string $saleId, string $revision, string $key, ?Closure $access = null): ReceiptData;
}
