<?php

namespace Modules\Sales\Application\Ports;

use Modules\Sales\Contracts\SaleSnapshot;

/** Puerto interno de borrador: no escribe ventas, líneas ni pagos confirmados. */
interface StagedSalesStore extends SalesStore
{
    public function snapshot(): SaleSnapshot;
}
