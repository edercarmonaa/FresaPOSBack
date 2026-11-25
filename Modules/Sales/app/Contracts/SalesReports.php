<?php

namespace Modules\Sales\Contracts;

use Modules\Core\Contracts\ReportQuery;
use Modules\Core\Contracts\ReportSlice;

interface SalesReports
{
    /** Requiere una transacción de lectura consistente externa. Nunca devuelve borradores. */
    public function read(string $actorId, string $report, ReportQuery $query): ReportSlice;
}
