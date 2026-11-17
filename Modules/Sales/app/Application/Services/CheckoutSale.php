<?php

namespace Modules\Sales\Application\Services;

use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Modules\Cash\Contracts\ActiveCashSession;
use Modules\Cash\Contracts\CashMovements;
use Modules\Cash\Contracts\CashSessionUnavailable;
use Modules\Cash\Contracts\PaymentMethods;
use Modules\Core\Contracts\ApiCode;
use Modules\Core\Contracts\DecimalAmount;
use Modules\Core\Contracts\IdempotentOperations;
use Modules\Core\Contracts\OperationIntent;
use Modules\Core\Contracts\OperationRejected;
use Modules\Core\Contracts\OperationResult;
use Modules\Core\Contracts\SaleCheckoutAudit;
use Modules\Core\Contracts\SecurityEvent;
use Modules\Core\Contracts\TransactionManager;
use Modules\Sales\Application\Ports\SalesStore;
use Modules\Sales\Application\SaleSnapshotCodec;
use Modules\Sales\Application\SalesRevision;
use Modules\Sales\Contracts\DiscountApproval;
use Modules\Sales\Contracts\Events\SaleChanged;
use Modules\Sales\Contracts\SaleCheckout;
use Modules\Sales\Contracts\SaleSnapshot;
use Modules\Sales\Domain\PaymentAllocation;
use Modules\Sales\Domain\SalesConflict;
use Modules\Sales\Domain\SaleState;
use Modules\Sales\Domain\SalesText;
use Modules\Sales\Infrastructure\Persistence\CheckoutStore;
use Modules\Sales\Infrastructure\Persistence\DailyFolios;
use Modules\Sales\Policies\SalesPolicy;
use Modules\Users\Contracts\IdentityAccess;

final class CheckoutSale implements SaleCheckout
{
    public function __construct(private readonly IdempotentOperations $operations, private readonly IdentityAccess $identities,
        private readonly SalesPolicy $policy, private readonly SalesStore $sales, private readonly ActiveCashSession $cash,
        private readonly PaymentMethods $methods, private readonly CheckoutStore $payments, private readonly DailyFolios $folios,
        private readonly CashMovements $movements, private readonly DiscountApproval $discounts, private readonly TransactionManager $transactions) {}

    private function authorize(string $actorId, bool $locking): void
    {
        if ($locking) {
            $this->identities->lock();
        }
        $actor = $this->identities->inspect($actorId);
        foreach (['sales.view', 'sales.close', 'cash.view'] as $permission) {
            if (! $actor || ! $this->policy->allows($actor, $permission)) {
                throw new AuthorizationException;
            }
        }
    }

    public function confirm(string $actorId, string $saleId, array $payments, string $key, ?string $revision): SaleSnapshot
    {
        $this->authorize($actorId, false);
        $saleId = SalesText::id($saleId);
        // Normaliza importes sin consultar métodos activos: replay conserva su resultado histórico.
        try {
            if (! array_is_list($payments) || count($payments) > 20) {
                throw new \InvalidArgumentException('Usa hasta veinte pagos.');
            }
            foreach ($payments as &$payment) {
                if (! is_array($payment) || array_diff(array_keys($payment), ['payment_method_id', 'applied_amount', 'received_cash', 'reference']) !== []) {
                    throw new \InvalidArgumentException('Campos de pago no permitidos.');
                }
                if (! is_string($payment['payment_method_id'] ?? null)) {
                    throw new \InvalidArgumentException('El método requiere un identificador estable.');
                }
                $payment['payment_method_id'] = SalesText::id($payment['payment_method_id']);
                $payment['applied_amount'] = DecimalAmount::parse($payment['applied_amount'] ?? null)->value;
                if (isset($payment['received_cash'])) {
                    $payment['received_cash'] = DecimalAmount::parse($payment['received_cash'])->value;
                }
                $payment['received_cash'] ??= null;
                $payment['reference'] ??= null;
                if ($payment['reference'] !== null && ! is_string($payment['reference'])) {
                    throw new \InvalidArgumentException('Referencia inválida.');
                }
            }
            unset($payment);
        } catch (\InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['payments' => $exception->getMessage()]);
        }
        $intent = new OperationIntent(OperationIntent::context($actorId), $key, 'sales.checkout', 'POST', $saleId, ['payments' => $payments, 'revision' => $revision], $actorId);
        $result = $this->operations->run($intent, fn (bool $locking) => $this->authorize($actorId, $locking), function () use ($actorId, $saleId, $payments, $revision): OperationResult {
            try {
                $initial = $this->sales->findSale($saleId);
                $this->cash->requireActive($actorId, $initial->sale->cashSessionId);
                $sale = $this->sales->findSale($saleId, lock: true);
                SalesRevision::requireCurrent($sale, $revision);
                if ($sale->sale->state !== SaleState::Open || $sale->sale->folio !== null || $sale->sale->cashSessionId !== $initial->sale->cashSessionId) {
                    throw new SalesConflict('La venta no admite cobro.');
                }
                $subtotal = DecimalAmount::from('0.0000');
                $lines = 0;
                foreach ($sale->items as $item) {
                    if ($item->removedAt === null) {
                        if ($item->quantity < 1) {
                            throw new SalesConflict('Línea inválida.');
                        }
                        $line = DecimalAmount::from($item->unitPrice)->multiply($item->quantity);
                        if ($line->value !== $item->lineTotal) {
                            throw new SalesConflict('Los importes históricos no son coherentes.');
                        }
                        $subtotal = $subtotal->add($line);
                        $lines++;
                    }
                }
                $total = $subtotal->subtract(DecimalAmount::from($sale->sale->discount));
                if ($lines === 0 || $subtotal->value !== $sale->sale->subtotal || $total->value !== $sale->sale->total) {
                    throw new SalesConflict('Revisa las líneas y el total vigente de la venta.');
                }
                if ($sale->sale->discount !== '0.0000') {
                    $this->discounts->requireApproved($actorId, $saleId, $subtotal->value, $sale->sale->discount);
                }
                if ($total->value === '0.0000' && ($sale->sale->discount === '0.0000' || $payments !== [])) {
                    throw new SalesConflict('El cierre sin pagos requiere un descuento total autorizado.');
                }
                $actor = $this->identities->inspect($actorId);
                $plan = PaymentAllocation::calculate($total->value, $this->methods->available($actorId), $payments, in_array('cash.administrative.execute', $actor->permissions, true));
                $closedAt = CarbonImmutable::now('UTC');
                $folio = $this->folios->next($closedAt);
                $this->payments->persist($actorId, $saleId, $sale->sale->cashSessionId, $plan, $closedAt);
                $closed = $this->sales->updateSale($saleId, ['status' => 'closed', 'folio' => $folio, 'closed_at' => $closedAt, 'closed_by' => $actorId]);
                // Valida también el rango del saldo agregado; nunca crea una entrada manual duplicada.
                $this->movements->balance($actorId, $sale->sale->cashSessionId);
                event(new SecurityEvent('sales.checkout.confirmed', actorId: $actorId, recordId: $saleId,
                    checkout: new SaleCheckoutAudit($sale->sale->cashSessionId, $folio, $total->value, $plan->cashApplied, count($plan->payments))));
                $this->transactions->afterCommit(function () use ($actorId, $saleId): void {
                    try {
                        event(new SaleChanged($saleId, $actorId, 'checkout'));
                    } catch (\Throwable $exception) {
                        try {
                            Log::error('Falló un consumidor secundario de checkout.', ['sale_id' => $saleId, 'exception_type' => $exception::class]);
                        } catch (\Throwable) {
                        }
                    }
                });

                return new OperationResult(200, SaleSnapshotCodec::encode($closed), $saleId);
            } catch (ModelNotFoundException) {
                throw new OperationRejected(ApiCode::NOT_FOUND);
            } catch (SalesConflict|CashSessionUnavailable) {
                throw new OperationRejected(ApiCode::CONFLICT);
            } catch (\InvalidArgumentException $exception) {
                throw new OperationRejected(ApiCode::VALIDATION_ERROR, ['payments' => [$exception->getMessage()]]);
            }
        });

        return SaleSnapshotCodec::decode($result->data);
    }
}
