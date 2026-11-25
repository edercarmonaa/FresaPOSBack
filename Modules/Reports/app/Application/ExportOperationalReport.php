<?php

namespace Modules\Reports\Application;

use Closure;
use Modules\Core\Contracts\ReportExportAudit;
use Modules\Core\Contracts\SecurityEvent;
use Modules\Core\Contracts\TransactionManager;
use Modules\Reports\Contracts\GeneratedReport;
use Modules\Reports\Infrastructure\ReportRenderer;
use Modules\Users\Contracts\IdentityAccess;

final class ExportOperationalReport
{
    public function __construct(private readonly QueryOperationalReports $reports, private readonly ReportRenderer $renderer,
        private readonly TransactionManager $transactions, private readonly IdentityAccess $identities) {}

    public function generate(string $actorId, string $report, string $format, array $filters = [], ?Closure $access = null): GeneratedReport
    {
        $data = $this->reports->read($actorId, $report, $filters, $access, true);
        $file = $this->renderer->render($data, $format);
        $this->transactions->run(function () use ($actorId, $data, $format, $access): void {
            $this->identities->lock();
            $access?->__invoke();
            $this->reports->authorize($actorId, true, $data->report);
            $fingerprint = hash('sha256', json_encode([$data->report, $data->filters, $data->items, $data->totals], JSON_THROW_ON_ERROR));
            event(new SecurityEvent('reports.export.generated', actorId: $actorId,
                reportExport: new ReportExportAudit($data->report, $format, $data->filters, $data->total, $fingerprint)));
        });

        return $file;
    }
}
