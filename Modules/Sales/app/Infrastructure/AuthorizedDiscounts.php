<?php

namespace Modules\Sales\Infrastructure;

use Illuminate\Support\Facades\DB;
use Modules\Sales\Application\Ports\SalesStore;
use Modules\Sales\Contracts\DiscountApproval;
use Modules\Sales\Domain\SalesConflict;
use Modules\Sales\Domain\SaleTotals;
use Modules\Sales\Policies\SalesPolicy;
use Modules\Users\Contracts\IdentityAccess;

/** Comprueba evidencia previa, nunca crea ni concede una autorización. */
final class AuthorizedDiscounts implements DiscountApproval
{
    public function __construct(private readonly SalesStore $sales, private readonly IdentityAccess $identities, private readonly SalesPolicy $policy) {}

    public function requireApproved(string $actorId, string $saleId, string $subtotal, string $discount): void
    {
        if (DB::connection()->transactionLevel() < 1) {
            throw new \LogicException('La validación del descuento requiere la transacción externa.');
        }
        $sale = $this->sales->findSale($saleId);
        $evidence = $sale->sale->discountAuthorization;
        $author = $evidence === null ? null : $this->identities->inspect($evidence['authorized_by']);
        foreach (['sales.view', 'sales.open.update', 'sales.discounts.apply', 'sales.special.authorize'] as $permission) {
            if (! $author || ! $this->policy->allows($author, $permission)) {
                throw new SalesConflict('El descuento necesita una autorización vigente.');
            }
        }
        $totals = SaleTotals::calculate($sale);
        if ($totals['subtotal'] !== $subtotal || $totals['discount_amount'] !== $discount || $sale->sale->total !== $totals['total']) {
            throw new SalesConflict('La autorización y los importes de descuento no son coherentes.');
        }
    }
}
