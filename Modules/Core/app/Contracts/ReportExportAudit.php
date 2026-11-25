<?php

namespace Modules\Core\Contracts;

final readonly class ReportExportAudit
{
    public bool $deliveryConfirmed;

    public function __construct(public string $report, public string $format, public array $filters, public int $rows, public string $dataFingerprint)
    {
        if (! preg_match('/\A[a-z-]{1,40}\z/', $report) || ! in_array($format, ['pdf', 'xlsx'], true) || $rows < 0 || $rows > 1000 || ! preg_match('/\A[a-f0-9]{64}\z/', $dataFingerprint)) {
            throw new \InvalidArgumentException('Evidencia de exportación inválida.');
        }
        foreach ($filters as $key => $value) {
            if (! in_array($key, ['range', 'from', 'until', 'page', 'per_page', 'sort', 'direction', 'employee_id', 'sale_type', 'payment_method', 'cash_session_id', 'product_id', 'category_id', 'discount_type', 'state', 'movement_type'], true)
                || ! is_scalar($value) || ! preg_match('/\A[A-Za-z0-9_.:\/-]{1,80}\z/', (string) $value)) {
                throw new \InvalidArgumentException('Filtro de auditoría no permitido.');
            }
        }
        $this->deliveryConfirmed = false;
    }
}
