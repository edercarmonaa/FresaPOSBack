<?php

namespace Modules\Sales\Contracts;

interface ConfirmedSalesDocuments
{
    /** Lectura coherente dentro de una transacción coordinada; nunca emite folios ni pagos. */
    public function find(string $actorId, ?string $saleId = null, ?string $folio = null): ConfirmedSaleDocument;
}
