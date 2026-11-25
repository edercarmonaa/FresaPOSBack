<?php

namespace Modules\Cash\Contracts;

use Modules\Core\Contracts\ReportQuery;
use Modules\Core\Contracts\ReportSlice;

interface CashReports
{
    /** Los resultados de arqueo proceden exclusivamente de cierres persistidos. */
    public function read(string $actorId, string $report, ReportQuery $query): ReportSlice;
}
