<?php

namespace Modules\Sales\Http\Livewire;

use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use Modules\Cash\Contracts\CashSessionUnavailable;
use Modules\Cash\Contracts\PaymentMethods;
use Modules\Core\Contracts\ApiCode;
use Modules\Core\Contracts\DecimalAmount;
use Modules\Core\Contracts\IdempotentOperations;
use Modules\Core\Contracts\OperationIntent;
use Modules\Core\Contracts\OperationRejected;
use Modules\Sales\Application\SalesRevision;
use Modules\Sales\Application\Services\PreparePayments;
use Modules\Sales\Application\Services\SalesAvailability;
use Modules\Sales\Contracts\SaleCheckout;
use Modules\Sales\Contracts\SalesOperations;
use Modules\Sales\Domain\SalesConflict;
use Modules\Sales\Domain\SalesDependencyUnavailable;
use Modules\Sales\Domain\SaleState;
use Modules\Sales\Http\PosAccess;
use Modules\Sales\Http\PosIntentRegistry;
use Modules\Sales\Http\Resources\SaleResource;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class CheckoutPanel extends Component
{
    #[Locked]
    public string $saleId;

    #[Locked]
    public string $actorContext = '';

    #[Locked]
    public string $key = '';

    #[Locked]
    public string $revision = '';

    #[Locked]
    public array $receipt = [];

    #[Locked]
    public array $preview = [];

    #[Locked]
    public bool $opened = false;

    #[Locked]
    public bool $pending = false;

    #[Locked]
    public bool $submitted = false;

    #[Locked]
    public string $status = '';

    public array $payments = [];

    private bool $written = false;

    #[On('sale-ticket-refreshed')]
    public function refreshTicket(string $saleId): void
    {
        $this->actor();
        if ($saleId === $this->saleId && ! $this->submitted && $this->receipt === [] && $this->opened) {
            $this->opened = false;
            $this->preview = $this->payments = [];
            $this->revision = '';
            $this->status = 'La venta cambió. Prepara nuevamente el cobro para utilizar el total vigente.';
        }
    }

    public function mount(string $saleId): void
    {
        $this->saleId = $saleId;
        $this->actorContext = $this->actor();
        $this->key = app(PosIntentRegistry::class)->key($this->actorContext, 'checkout.'.$saleId);
        $this->queryCheckout();
    }

    private function actor(): string
    {
        $actor = app(PosAccess::class)->require('sales.close');
        abort_unless(in_array('cash.view', $actor->permissions, true) && ($this->actorContext === '' || $this->actorContext === $actor->id), 403);

        return $actor->id;
    }

    private function attempt(Closure $operation): void
    {
        $this->resetErrorBag();
        try {
            $operation();
        } catch (OperationRejected $e) {
            $this->pending = $e->apiCode === ApiCode::OPERATION_IN_PROGRESS;
            $this->addError('checkout', $e->getMessage());
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                $this->addError($field, $messages[0]);
            }
        } catch (SalesConflict|SalesDependencyUnavailable|CashSessionUnavailable|\InvalidArgumentException $e) {
            $this->addError('checkout', $e->getMessage());
        } catch (\Throwable $e) {
            if ($e instanceof AuthorizationException || $e instanceof HttpExceptionInterface) {
                throw $e;
            }
            report($e);
            $this->addError('checkout', 'No se pudo confirmar el resultado. Consulta el estado antes de reintentar.');
        }
    }

    public function openCheckout(): void
    {
        $actor = $this->actor();
        $this->attempt(function () use ($actor): void {
            $this->queryCheckout();
            if ($this->pending || $this->receipt !== []) {
                return;
            }
            $state = app(IdempotentOperations::class)->status(OperationIntent::context($actor), $this->key);
            if ($state->state === 'failed') {
                $this->key = app(PosIntentRegistry::class)->key($actor, 'checkout.'.$this->saleId, true);
            } elseif ($this->submitted) {
                throw new SalesConflict('Consulta o reintenta la intención original antes de cambiar los pagos.');
            }
            $sale = app(SalesOperations::class)->sale($actor, $this->saleId);
            if ($sale->sale->state !== SaleState::Open) {
                throw new SalesConflict('La venta no está abierta para cobro.');
            }
            if (! app(SalesAvailability::class)->cash($actor, $sale->sale->cashSessionId)) {
                throw new SalesConflict('La sesión de caja original no está abierta. Actualiza el estado de caja.');
            }
            $this->revision = '"'.SalesRevision::of($sale).'"';
            $methods = app(PaymentMethods::class)->available($actor);
            $cash = array_values(array_filter($methods, fn ($m) => $m->code === 'cash'));
            $defaultId = isset($cash[0]) ? $cash[0]->id : (isset($methods[0]) ? $methods[0]->id : '');
            $this->payments = $sale->sale->total === '0.0000' ? [] : [['payment_method_id' => $defaultId, 'applied_amount' => $sale->sale->total, 'received_cash' => isset($cash[0]) ? $sale->sale->total : null, 'reference' => null]];
            $this->preview = ['total' => $sale->sale->total, 'change' => '0.0000', 'remaining' => $sale->sale->total];
            $this->opened = true;
            $this->submitted = false;
            $this->status = 'Revisa los pagos y calcula el cambio antes de confirmar.';
        });
    }

    public function addPayment(): void
    {
        $this->actor();
        abort_if($this->submitted || $this->pending || ! $this->opened || count($this->payments) >= 20, 422);
        $this->payments[] = ['payment_method_id' => '', 'applied_amount' => '', 'received_cash' => null, 'reference' => null];
        $this->preview = [];
    }

    public function removePayment(int $index): void
    {
        $this->actor();
        abort_if($this->submitted || $this->pending || ! isset($this->payments[$index]), 422);
        unset($this->payments[$index]);
        $this->payments = array_values($this->payments);
        $this->preview = [];
    }

    public function updated(string $property): void
    {
        $actor = $this->actor();
        $this->preview = [];
        if (preg_match('/\Apayments\.([0-9]+)\.payment_method_id\z/', $property, $match) === 1) {
            $index = (int) $match[1];
            $methods = app(PaymentMethods::class)->available($actor);
            foreach ($methods as $method) {
                if ($method->id === ($this->payments[$index]['payment_method_id'] ?? '') && $method->code !== 'cash') {
                    $this->payments[$index]['received_cash'] = null;
                }
            }
        }
    }

    private function input(): array
    {
        $this->validate(['payments' => ['array', 'list', 'max:20'], 'payments.*' => ['array:payment_method_id,applied_amount,received_cash,reference']]);

        return array_map(function (array $p): array {
            foreach (['received_cash', 'reference'] as $field) {
                if (($p[$field] ?? null) === '') {
                    $p[$field] = null;
                }
            }

            return $p;
        }, $this->payments);
    }

    public function calculate(): void
    {
        $actor = $this->actor();
        $this->attempt(function () use ($actor): void {
            $sale = app(SalesOperations::class)->sale($actor, $this->saleId);
            SalesRevision::requireCurrent($sale, $this->revision);
            $plan = app(PreparePayments::class)->quote($actor, $this->saleId, $this->input(), false);
            $change = DecimalAmount::from('0.0000');
            foreach ($plan->payments as $payment) {
                if ($payment->changeCash !== null) {
                    $change = $change->add(DecimalAmount::from($payment->changeCash));
                }
            }
            $this->preview = ['total' => $plan->total, 'remaining' => $plan->remaining, 'change' => $change->value];
        });
    }

    public function confirmCheckout(): void
    {
        $actor = $this->actor();
        if ($this->written || $this->receipt !== []) {
            return;
        }
        $this->attempt(function () use ($actor): void {
            abort_unless($this->opened && $this->revision !== '', 422);
            $payments = $this->input();
            app(PosIntentRegistry::class)->begin($actor, $this->key, 'sales.checkout', $this->saleId);
            $this->submitted = true;
            $sale = app(SaleCheckout::class)->confirm($actor, $this->saleId, $payments, $this->key, $this->revision);
            $this->written = true;
            $this->receipt = (new SaleResource($sale))->resolve(request());
            $this->pending = false;
            $this->status = $sale->sale->total === '0.0000' ? 'Venta cerrada con descuento total. Conserva el folio de confirmación.' : 'Venta cobrada. Conserva el folio de confirmación.';
            $this->dispatch('sale-checked-out', saleId: $this->saleId);
        });
    }

    public function queryCheckout(): void
    {
        $actor = $this->actor();
        $this->attempt(function () use ($actor): void {
            $state = app(IdempotentOperations::class)->status(OperationIntent::context($actor), $this->key);
            $this->pending = $state->state === 'pending';
            $this->submitted = in_array($state->state, ['pending', 'completed', 'failed'], true) || $this->submitted;
            $sale = app(SalesOperations::class)->sale($actor, $this->saleId);
            if ($sale->sale->state === SaleState::Closed) {
                $this->receipt = (new SaleResource($sale))->resolve(request());
                $this->opened = true;
                $this->status = $sale->sale->total === '0.0000' ? 'Venta cerrada con descuento total. Resultado recuperado del servidor.' : 'Venta cobrada. Resultado recuperado del servidor.';
            } elseif ($this->pending) {
                $this->status = 'Cobro en proceso. Consulta el estado antes de reintentar.';
            } elseif ($state->state === 'failed') {
                $this->status = 'Cobro rechazado. Usa Preparar cobro para revisar y crear otra intención.';
            }
        });
    }

    public function render(): View
    {
        $actor = $this->actor();
        $methods = app(PaymentMethods::class)->available($actor);

        return view('sales::livewire.checkout-panel', compact('methods'));
    }
}
