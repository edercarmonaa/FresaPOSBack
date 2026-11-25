<?php

namespace Modules\Reports\Contracts;

use Closure;

interface OperationalReports
{
    public function read(string $actorId, string $report, array $filters = [], ?Closure $access = null, bool $export = false): ReportData;
}
