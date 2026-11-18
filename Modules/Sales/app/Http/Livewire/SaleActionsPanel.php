<?php

namespace Modules\Sales\Http\Livewire;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use Modules\Core\Contracts\ApiCode;
use Modules\Core\Contracts\IdempotentOperations;
use Modules\Core\Contracts\OperationIntent;
use Modules\Core\Contracts\OperationRejected;
use Modules\Sales\Application\SalesRevision;
use Modules\Sales\Application\Services\CoordinateSalesWrite;
use Modules\Sales\Contracts\CriticalSalesWrites;
use Modules\Sales\Contracts\SaleOperationReasons;
use Modules\Sales\Contracts\SalesOperations;
use Modules\Sales\Domain\SaleState;
use Modules\Sales\Http\PosAccess;
use Modules\Sales\Http\PosIntentRegistry;
use Modules\Sales\Http\Resources\SaleResource;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class SaleActionsPanel extends Component
{
    #[Locked]
    public string $saleId;

    #[Locked]
    public string $actorContext = '';

    #[Locked]
    public string $action = '';

    #[Locked]
    public ?string $itemId = null;

    #[Locked]
    public string $revision = '';

    #[Locked]
    public string $key = '';

    #[Locked]
    public bool $submitted = false;

    #[Locked]
    public bool $formOpen = false;

    #[Locked]
    public bool $pending = false;

    #[Locked]
    public array $payload = [];

    #[Locked]
    public array $reasons = [];

    #[Locked]
    public int $reasonPage = 1;

    #[Locked]
    public int $reasonTotal = 0;

    #[Locked]
    public array $target = [];

    #[Locked]
    public array $result = [];

    public string $reasonCode = '';

    public string $status = '';

    private bool $written = false;

    #[On('sale-ticket-refreshed')]
    public function refreshTicket(string $saleId): void
    {
        $this->actor();
        abort_unless($saleId === $this->saleId, 403);
    }

    private function actor(bool $write = false): string
    {
        $permission = $write ? ($this->action === 'remove' ? 'sales.special.authorize' : 'sales.cancel') : 'sales.view';
        $actor = app(PosAccess::class)->require($permission);
        abort_if($this->actorContext !== '' && $this->actorContext !== $actor->id, 403);
        if ($write && $this->action === 'remove') {
            app(PosAccess::class)->require('sales.open.update');
        }

        return $actor->id;
    }

    private function path(): string
    {
        return 'sales_control_intents.'.OperationIntent::context($this->actor()).'.'.$this->saleId;
    }

    public function mount(string $saleId): void
    {
        $this->saleId = $saleId;
        $this->actorContext = $this->actor();
        $intent = session()->get($this->path());
        if (is_array($intent)) {
            [$this->key, $this->action, $this->itemId, $this->revision, $this->payload, $this->target] = $intent;
            $this->submitted = $this->formOpen = true;
            $this->reasonCode = $this->payload['reason_code'];
            $this->queryOperation();
        }
    }

    private function prepare(string $action, ?string $itemId = null): void
    {
        $this->actor();
        if ($this->submitted) {
            $previous = app(IdempotentOperations::class)->status(OperationIntent::context($this->actor()), $this->key);
            if (in_array($previous->state, ['pending', 'not_found'], true)) {
                $this->addError('operation', 'Resuelve la intención anterior conservando su clave antes de preparar otra.');

                return;
            }
        }
        $this->action = $action;
        $actor = $this->actor(true);
        $sale = app(SalesOperations::class)->sale($actor, $this->saleId);
        if (! in_array($sale->sale->state, $action === 'remove' ? [SaleState::Open] : [SaleState::Open, SaleState::Paused], true)) {
            $this->addError('operation', 'Esta venta no admite la operación.');

            return;
        }
        $this->itemId = $itemId;
        $this->target = ['total' => $sale->sale->total];
        if ($action === 'remove') {
            $item = collect($sale->items)->first(fn ($item) => $item->id === $itemId && $item->removedAt === null);
            abort_if($item === null, 404);
            $this->target += ['name' => $item->name, 'quantity' => $item->quantity, 'line_total' => $item->lineTotal];
        }
        $this->loadReasons(1);
        $this->revision = '"'.SalesRevision::of($sale).'"';
        $this->key = (string) Str::uuid();
        $this->reasonCode = '';
        $this->payload = $this->result = [];
        $this->submitted = $this->pending = false;
        $this->formOpen = true;
        $this->resetErrorBag();
    }

    public function prepareRemoval(string $itemId): void
    {
        $this->prepare('remove', $itemId);
    }

    public function prepareCancellation(): void
    {
        $this->prepare('cancel');
    }

    private function loadReasons(int $page): void
    {
        $list = app(SaleOperationReasons::class)->search($this->actor(true), ['operation' => $this->action === 'remove' ? 'sale_item_removal' : 'sale_cancellation', 'page' => $page, 'per_page' => 25]);
        $this->reasons = $list['items'];
        $this->reasonPage = $page;
        $this->reasonTotal = $list['total'];
    }

    public function reasonPage(int $page): void
    {
        abort_if($this->submitted || ! $this->formOpen || $page < 1 || $page > max(1, (int) ceil($this->reasonTotal / 25)), 422);
        $this->loadReasons($page);
        $this->reasonCode = '';
    }

    public function confirmOperation(): void
    {
        $actor = $this->actor(true);
        if ($this->written) {
            return;
        }
        $this->resetErrorBag();
        try {
            abort_unless(($this->formOpen || $this->submitted) && in_array($this->action, ['remove', 'cancel'], true), 422);
            if (! $this->submitted) {
                $this->validate(['reasonCode' => ['required', 'regex:/\A[A-Z0-9][A-Z0-9_:-]{0,63}\z/']]);
                $this->payload = ['reason_code' => $this->reasonCode, 'revision' => $this->revision];
                $this->submitted = true;
            }
            $scope = 'sales.'.$this->saleId.'.'.$this->action.($this->action === 'remove' ? '.'.$this->itemId : '');
            session()->put($this->path(), [$this->key, $this->action, $this->itemId, $this->revision, $this->payload, $this->target]);
            session()->save();
            app(PosIntentRegistry::class)->begin($actor, $this->key, $scope, $this->saleId);
            $this->written = true;
            $sale = app(CriticalSalesWrites::class)->run($actor, $scope, $this->key, $this->payload,
                fn () => app(CoordinateSalesWrite::class)->run($actor, $this->saleId, false, $this->revision,
                    fn () => $this->action === 'remove'
                        ? app(SalesOperations::class)->removeItem($actor, $this->saleId, $this->itemId, $this->payload['reason_code'])
                        : app(SalesOperations::class)->cancel($actor, $this->saleId, $this->payload['reason_code'])));
            $this->result = (new SaleResource($sale))->resolve(request());
            $this->formOpen = $this->pending = false;
            $this->status = 'Operación confirmada. La historia se conservó.';
            $this->dispatch('sale-operation-completed', saleId: $this->saleId);
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                $this->addError($field, $messages[0]);
            }
        } catch (OperationRejected $exception) {
            $this->pending = $exception->apiCode === ApiCode::OPERATION_IN_PROGRESS;
            $this->addError('operation', $exception->getMessage().' Consulta el estado antes de preparar otra intención.');
        } catch (\Throwable $exception) {
            if ($exception instanceof AuthorizationException || $exception instanceof HttpExceptionInterface) {
                throw $exception;
            }
            report($exception);
            $this->addError('operation', 'No hay un resultado confirmado. Conservamos la intención; consulta su estado o reintenta la misma solicitud.');
        }
    }

    public function queryOperation(): void
    {
        $actor = $this->actor(true);
        if ($this->key === '') {
            return;
        }
        $state = app(IdempotentOperations::class)->status(OperationIntent::context($actor), $this->key);
        $this->pending = $state->state === 'pending';
        $this->status = 'Estado de la intención: '.($state->expired ? 'expired' : $state->state).'. Conservamos su clave.';
        if ($state->state === 'completed' && $state->resourceId === $this->saleId && $state->operation === 'sales.'.$this->action) {
            $sale = app(SalesOperations::class)->sale($actor, $this->saleId);
            $this->result = (new SaleResource($sale))->resolve(request());
            $this->formOpen = false;
            $this->status = 'La operación ya se confirmó. Mostramos la venta vigente sin ejecutar otra operación.';
            $this->dispatch('sale-operation-completed', saleId: $this->saleId);
        }
    }

    public function render(): View
    {
        $actor = app(PosAccess::class)->require('sales.view');
        $this->actor();
        $sale = app(SalesOperations::class)->sale($actor->id, $this->saleId);

        return view('sales::livewire.sale-actions-panel', ['sale' => $sale,
            'canRemove' => in_array('sales.special.authorize', $actor->permissions, true) && in_array('sales.open.update', $actor->permissions, true),
            'canCancel' => in_array('sales.cancel', $actor->permissions, true)]);
    }
}
