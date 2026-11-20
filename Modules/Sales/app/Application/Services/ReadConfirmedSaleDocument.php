<?php

namespace Modules\Sales\Application\Services;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Modules\Core\Contracts\DecimalAmount;
use Modules\Sales\Application\Ports\SalesStore;
use Modules\Sales\Application\SalesRevision;
use Modules\Sales\Contracts\ConfirmedSaleDocument;
use Modules\Sales\Contracts\ConfirmedSalesDocuments;
use Modules\Sales\Contracts\SaleQuery;
use Modules\Sales\Domain\SalesConflict;
use Modules\Sales\Domain\SaleState;
use Modules\Sales\Domain\SalesText;
use Modules\Sales\Domain\SaleTotals;
use Modules\Sales\Infrastructure\Persistence\CorrectionStore;
use Modules\Sales\Policies\SalesPolicy;
use Modules\Users\Contracts\IdentityAccess;

final class ReadConfirmedSaleDocument implements ConfirmedSalesDocuments
{
    public function __construct(private readonly SalesStore $sales, private readonly CorrectionStore $corrections,
        private readonly IdentityAccess $identities, private readonly SalesPolicy $policy) {}

    public function find(string $actorId, ?string $saleId = null, ?string $folio = null): ConfirmedSaleDocument
    {
        $actor = $this->identities->inspect($actorId);
        if (! $actor || ! $this->policy->allows($actor, 'sales.view')) {
            throw new AuthorizationException;
        }
        if (($saleId === null) === ($folio === null)) {
            throw new \InvalidArgumentException('Usa una referencia de venta o folio.');
        }
        if ($folio !== null) {
            $page = $this->sales->sales(new SaleQuery(folio: $folio));
            if ($page->items === []) {
                throw new ModelNotFoundException;
            }
            $saleId = $page->items[0]->id;
        }
        $sale = $this->sales->findSale(SalesText::id($saleId), lock: true);
        $summary = $sale->sale;
        if ($summary->state !== SaleState::Closed || $summary->folio === null || $summary->closedAt === null || $summary->closedBy === null) {
            throw new SalesConflict('Solo una venta cerrada con folio admite comprobante definitivo.');
        }
        if ($this->corrections->exists($summary->id)) {
            throw new SalesConflict('Hay una corrección activa. Confírmala o descártala antes de emitir el ticket.');
        }
        try {
            $totals = SaleTotals::calculate($sale);
            if ($totals['subtotal'] === '0.0000' || $totals['subtotal'] !== $summary->subtotal || $totals['discount_amount'] !== $summary->discount || $totals['total'] !== $summary->total) {
                throw new SalesConflict('La información histórica no permite emitir un comprobante coherente.');
            }
            $paid = DecimalAmount::from('0.0000');
            foreach ($sale->payments as $payment) {
                if ($payment['status'] !== 'confirmed' || $payment['sale_id'] !== $summary->id || $payment['cash_session_id'] !== $summary->cashSessionId
                    || ! in_array($payment['method_code'], ['cash', 'card', 'transfer', 'other'], true)) {
                    throw new SalesConflict('Los pagos históricos no son consistentes.');
                }
                $applied = DecimalAmount::from($payment['applied_amount']);
                if ($payment['method_code'] === 'cash') {
                    if (DecimalAmount::from($payment['received_cash'])->subtract($applied)->value !== $payment['change_cash']) {
                        throw new SalesConflict('El efectivo histórico no es consistente.');
                    }
                } elseif ($payment['received_cash'] !== null || $payment['change_cash'] !== null) {
                    throw new SalesConflict('El pago no efectivo tiene datos incompatibles.');
                }
                $paid = $paid->add($applied);
            }
            if ($paid->value !== $summary->total || ($summary->total === '0.0000' && ($sale->payments !== [] || $summary->discount === '0.0000'))) {
                throw new SalesConflict('El comprobante requiere pagos conciliados con el total confirmado.');
            }

        } catch (\InvalidArgumentException) {
            throw new SalesConflict('Los importes históricos no permiten emitir un comprobante coherente.');
        }

        return new ConfirmedSaleDocument($sale, SalesRevision::of($sale), $this->corrections->latestConfirmed($summary->id));
    }
}
