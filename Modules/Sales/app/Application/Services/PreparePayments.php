<?php

namespace Modules\Sales\Application\Services;

use Illuminate\Auth\Access\AuthorizationException;
use Modules\Cash\Contracts\ActiveCashSession;
use Modules\Cash\Contracts\PaymentMethods;
use Modules\Core\Contracts\TransactionManager;
use Modules\Sales\Contracts\PaymentPlan;
use Modules\Sales\Contracts\SalesOperations;
use Modules\Sales\Domain\PaymentAllocation;
use Modules\Sales\Domain\SalesConflict;
use Modules\Sales\Domain\SaleState;
use Modules\Users\Contracts\IdentityAccess;

/** Cotización interna autorizada: no guarda pagos, no cobra y no cierra. */
final class PreparePayments
{
    public function __construct(private readonly TransactionManager $transactions, private readonly IdentityAccess $identities, private readonly SalesOperations $sales, private readonly ActiveCashSession $cash, private readonly PaymentMethods $methods) {}

    public function quote(string $actorId, string $saleId, array $payments, bool $requireComplete = true): PaymentPlan
    {
        return $this->transactions->run(function () use ($actorId, $saleId, $payments, $requireComplete): PaymentPlan {
            $this->identities->lock();
            $actor = $this->identities->inspect($actorId);
            if (! $actor || ! $actor->isActive || $actor->mustChangePassword || array_diff(['sales.view', 'sales.close', 'cash.view'], $actor->permissions) !== []) {
                throw new AuthorizationException;
            }
            $sale = $this->sales->sale($actorId, $saleId);
            if ($sale->sale->state !== SaleState::Open) {
                throw new SalesConflict('La venta no admite preparación de cobro.');
            }
            $this->cash->requireActive($actorId, $sale->sale->cashSessionId);

            return PaymentAllocation::calculate($sale->sale->total, $this->methods->available($actorId), $payments, in_array('cash.administrative.execute', $actor->permissions, true), $requireComplete);
        });
    }
}
