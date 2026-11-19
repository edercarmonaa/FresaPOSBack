<?php

namespace Modules\Sales\Http\Livewire;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use Modules\Auth\Contracts\AuthenticatedRequestAccess;
use Modules\Core\Contracts\IdempotentOperations;
use Modules\Core\Contracts\OperationIntent;
use Modules\Core\Contracts\OperationRejected;
use Modules\Sales\Application\SalesRevision;
use Modules\Sales\Application\Services\CoordinateSalesWrite;
use Modules\Sales\Contracts\CriticalSalesWrites;
use Modules\Sales\Contracts\DiscountProposal;
use Modules\Sales\Contracts\SalesOperations;
use Modules\Sales\Domain\SalesConflict;
use Modules\Sales\Domain\SaleTotals;
use Modules\Sales\Http\PosAccess;
use Modules\Sales\Http\PosIntentRegistry;
use Modules\Sales\Http\Resources\SaleResource;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class DiscountPanel extends Component
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
    public bool $removing = false;

    #[Locked]
    public bool $opened = false;

    #[Locked]
    public bool $submitted = false;

    #[Locked]
    public array $frozen = [];

    #[Locked]
    public array $preview = [];

    #[Locked]
    public array $result = [];

    public string $type = 'percent';

    public string $value = '';

    public string $status = '';

    private bool $written = false;

    private function actor(): string
    {
        $actor = app(PosAccess::class)->require('sales.discounts.apply');
        abort_unless(($this->actorContext === '' || $this->actorContext === $actor->id)
            && array_diff(['sales.open.update', 'sales.special.authorize'], $actor->permissions) === [], 403);

        return $actor->id;
    }

    private function path(): string
    {
        return 'sales_discount_intents.'.OperationIntent::context($this->actor()).'.'.$this->saleId;
    }

    public function mount(string $saleId): void
    {
        $this->saleId = $saleId;
        $this->actorContext = $this->actor();
        $intent = session()->get($this->path());
        if (is_array($intent)) {
            [$this->key,$this->revision,$this->removing,$this->frozen] = $intent;
            $this->type = $this->frozen['type'] ?? 'percent';
            $this->value = (string) ($this->frozen['value'] ?? '');
            $this->submitted = $this->opened = true;
            $this->queryOperation();
        }
    }

    #[On('sale-ticket-refreshed')]
    public function refreshTicket(string $saleId): void
    {
        $this->actor();
        abort_unless($saleId === $this->saleId, 403);
    }

    public function prepare(bool $removing = false): void
    {
        $actor = $this->actor();
        if ($this->submitted && in_array(app(IdempotentOperations::class)->status(OperationIntent::context($actor), $this->key)->state, ['pending', 'not_found'], true)) {
            $this->addError('discount', 'Conserva y resuelve la intención anterior antes de preparar otra.');

            return;
        }
        $sale = app(SalesOperations::class)->sale($actor, $this->saleId);
        if ($sale->sale->state->value !== 'open') {
            $this->addError('discount', 'La venta no está abierta para edición.');

            return;
        }
        $this->resetErrorBag();
        $this->removing = $removing;
        $this->key = (string) Str::uuid();
        $this->revision = '"'.SalesRevision::of($sale).'"';
        $this->submitted = false;
        $this->opened = true;
        $this->frozen = $this->result = $this->preview = [];
        $this->type = $sale->sale->discountAuthorization['type'] ?? 'percent';
        $this->value = (string) ($sale->sale->discountAuthorization['value'] ?? '');
    }

    private function proposal(): ?DiscountProposal
    {
        if ($this->removing) {
            return null;
        }
        if ($this->type === 'percent' && preg_match('/\A(?:[1-9]|[1-9][0-9]|100)\z/', $this->value) !== 1) {
            throw new \InvalidArgumentException('Usa un porcentaje entero entre 1 y 100.');
        }

        return new DiscountProposal($this->type, $this->type === 'percent' ? (int) $this->value : $this->value);
    }

    public function calculate(): void
    {
        $actor = $this->actor();
        abort_if(! $this->opened || $this->submitted, 422);
        $this->resetErrorBag();
        try {
            $sale = app(SalesOperations::class)->sale($actor, $this->saleId);
            SalesRevision::requireCurrent($sale, $this->revision);
            $this->preview = SaleTotals::calculate($sale, $this->proposal(), $this->removing);
        } catch (\InvalidArgumentException|SalesConflict $e) {
            $this->addError('discount', $e->getMessage());
        }
    }

    public function confirm(): void
    {
        $actor = $this->actor();
        if ($this->written) {
            return;
        }$this->resetErrorBag();
        try {
            abort_unless($this->opened || $this->submitted, 422);
            if (! $this->submitted) {
                $proposal = $this->proposal();
                $this->frozen = ($proposal === null ? [] : ['type' => $proposal->type, 'value' => $proposal->value]) + ['revision' => $this->revision];
                $this->submitted = true;
            }
            $scope = 'sales.'.$this->saleId.'.'.($this->removing ? 'discount-remove' : 'discount');
            session()->put($this->path(), [$this->key, $this->revision, $this->removing, $this->frozen]);
            session()->save();
            app(PosIntentRegistry::class)->begin($actor, $this->key, $scope, $this->saleId);
            $this->written = true;
            $sale = app(CriticalSalesWrites::class)->run($actor, $scope, $this->key, $this->frozen,
                fn () => app(CoordinateSalesWrite::class)->run($actor, $this->saleId, false, $this->revision,
                    fn () => app(SalesOperations::class)->setDiscount($actor, $this->saleId, $this->removing ? null : new DiscountProposal($this->frozen['type'], $this->frozen['value']))),
                access: fn () => app(AuthenticatedRequestAccess::class)->requireWeb(request(), $actor));
            $this->result = (new SaleResource($sale))->resolve(request());
            $this->opened = false;
            $this->status = 'Descuento actualizado con autorización. El historial se conservó.';
            $this->dispatch('sale-operation-completed', saleId: $this->saleId);
        } catch (\InvalidArgumentException $e) {
            $this->addError('discount', $e->getMessage());
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                $this->addError($field, $messages[0]);
            }
        } catch (OperationRejected $e) {
            $this->addError('discount', $e->getMessage().' Consulta la intención antes de preparar otra.');
        } catch (\Throwable $e) {
            if ($e instanceof AuthorizationException || $e instanceof HttpExceptionInterface) {
                throw $e;
            }report($e);
            $this->addError('discount', 'Resultado no confirmado. Conservamos la solicitud; consulta su estado o reintenta la misma intención.');
        }
    }

    public function queryOperation(): void
    {
        $actor = $this->actor();
        if ($this->key === '') {
            return;
        }
        $state = app(IdempotentOperations::class)->status(OperationIntent::context($actor), $this->key);
        $this->status = 'Estado de la intención: '.($state->expired ? 'expired' : $state->state).'. Conservamos su clave.';
        if ($state->state === 'completed' && $state->resourceId === $this->saleId && in_array($state->operation, ['sales.discount', 'sales.discount-remove'], true)) {
            $this->result = (new SaleResource(app(SalesOperations::class)->sale($actor, $this->saleId)))->resolve(request());
            $this->opened = false;
            $this->status = 'Descuento confirmado. Resultado recuperado sin repetir efectos.';
            $this->dispatch('sale-operation-completed', saleId: $this->saleId);
        }
    }

    public function updated(): void
    {
        $this->preview = [];
    }

    public function render(): View
    {
        $sale = app(SalesOperations::class)->sale($this->actor(), $this->saleId);

        return view('sales::livewire.discount-panel', compact('sale'));
    }
}
