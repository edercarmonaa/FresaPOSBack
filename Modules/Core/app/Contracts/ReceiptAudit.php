<?php

namespace Modules\Core\Contracts;

final readonly class ReceiptAudit
{
    public string $action;

    public bool $physicalPrintConfirmed;

    public function __construct(public string $folio, public string $revision)
    {
        if (preg_match('/\A[0-9]{12}\z/', $folio) !== 1 || preg_match('/\A[a-f0-9]{64}\z/', $revision) !== 1) {
            throw new \InvalidArgumentException('El ticket requiere folio y revisión confirmados.');
        }
        $this->action = 'reprint_requested_document_prepared';
        $this->physicalPrintConfirmed = false;
    }
}
