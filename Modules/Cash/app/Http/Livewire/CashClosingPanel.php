<?php

namespace Modules\Cash\Http\Livewire;

use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Modules\Cash\Contracts\CashClosings;
use Modules\Cash\Contracts\CashOperations;
use Modules\Cash\Http\CashAccess;
use Modules\Core\Contracts\ApiCode;
use Modules\Core\Contracts\IdempotentOperations;
use Modules\Core\Contracts\OperationIntent;
use Modules\Core\Contracts\OperationRejected;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class CashClosingPanel extends Component
{
    #[Locked]
    public string $actorContext = '';

    #[Locked]
    public ?string $cashSessionId = null;

    #[Locked]
    public string $key = '';

    #[Locked]
    public array $receipt = [];

    #[Locked]
    public array $pendingSales = [];

    #[Locked]
    public int $pendingTotal = 0;

    #[Locked]
    public bool $formOpen = false;

    #[Locked]
    public bool $pending = false;

    #[Locked]
    public bool $submitted = false;

    public string $countedCash = '';

    public string $observation = '';

    public string $date = '';

    public string $closedBy = '';

    public string $registerId = '';

    public string $historyStatus = 'closed';

    public int $page = 1;

    private bool $written = false;

    private function actor(string $permission = 'cash.closings.view'): string
    {
        $actor = app(CashAccess::class)->require($permission);
        abort_unless($this->actorContext === '' || $actor->id === $this->actorContext, 403);

        return $actor->id;
    }

    private function path(): string
    {
        return 'cash_closing.'.OperationIntent::context($this->actorContext);
    }

    public function mount(): void
    {
        $this->actorContext = $this->actor();
        $metadata = session()->get($this->path(), []);
        $this->cashSessionId = $metadata['session'] ?? null;
        $this->key = $metadata['key'] ?? '';
        $this->submitted = $metadata['submitted'] ?? false;
        $this->formOpen = $this->submitted;
        if ($this->key !== '') {
            $this->queryClosing();
        }
        if ($this->formOpen && $this->receipt === []) {
            $this->dispatch('cash-count-started');
        }
    }

    private function attempt(Closure $action): void
    {
        $this->resetErrorBag();
        try {
            $action();
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                $this->addError($field, $messages[0]);
            }
        } catch (OperationRejected $e) {
            $this->pending = $e->apiCode === ApiCode::OPERATION_IN_PROGRESS;
            foreach ($e->errors ?: ['closing' => [$e->getMessage()]] as $field => $messages) {
                $this->addError($field, $messages[0]);
            }
        } catch (\Throwable $e) {
            if ($e instanceof AuthorizationException || $e instanceof HttpExceptionInterface) {
                throw $e;
            }
            report($e);
            $this->addError('closing', 'No se conoce el resultado. Consulta el estado antes de reintentar con la misma clave.');
        }
    }

    public function beginClosing(): void
    {
        $actor = $this->actor('cash.close');
        $this->attempt(function () use ($actor): void {
            $this->queryClosing();
            if ($this->pending) {
                return;
            }
            $session = app(CashOperations::class)->current($actor);
            if (! $session) {
                $this->addError('closing', 'No hay sesión abierta.');

                return;
            }
            if ($this->submitted && $this->cashSessionId === $session->id) {
                $state = app(IdempotentOperations::class)->status(OperationIntent::context($actor), $this->key);
                if (! in_array($state->state, ['failed', 'completed'], true)) {
                    $this->addError('closing', 'Consulta o reintenta la intención original antes de preparar otra.');

                    return;
                }
            }
            $this->cashSessionId = $session->id;
            $this->receipt = [];
            $pending = app(CashClosings::class)->pending($actor, $session->id);
            $this->pendingSales = $pending['items'];
            $this->pendingTotal = $pending['total'];
            $this->formOpen = $pending['total'] === 0;
            if (! $this->formOpen) {
                return;
            }
            $this->key = (string) Str::uuid();
            $this->submitted = false;
            $this->countedCash = '';
            $this->observation = '';
            $this->remember();
            $this->dispatch('cash-count-started');
        });
    }

    private function remember(): void
    {
        session()->put($this->path(), ['session' => $this->cashSessionId, 'key' => $this->key, 'submitted' => $this->submitted]);
        session()->save();
    }

    public function confirmClosing(): void
    {
        $actor = $this->actor('cash.close');
        if ($this->written) {
            return;
        }
        $this->written = true;
        $this->submitted = true;
        $this->attempt(function () use ($actor): void {
            if ($this->cashSessionId === null || $this->key === '') {
                $this->addError('closing', 'Prepara el conteo de la sesión actual.');

                return;
            }
            $this->remember();
            $this->receipt = app(CashClosings::class)->close($actor, $this->cashSessionId, $this->countedCash, $this->observation, $this->key);
            $this->pending = false;
            $this->formOpen = false;
            $this->dispatch('cash-session-closed');
        });
    }

    public function queryClosing(): void
    {
        $actor = $this->actor();
        if ($this->key === '' || $this->cashSessionId === null) {
            return;
        }
        $this->attempt(function () use ($actor): void {
            $state = app(IdempotentOperations::class)->status(OperationIntent::context($actor), $this->key);
            $this->pending = $state->state === 'pending';
            if (in_array($state->state, ['completed', 'expired'], true) && $state->operation === 'cash.session.close' && $state->resourceId === $this->cashSessionId) {
                $this->receipt = app(CashClosings::class)->reconciliation($actor, $this->cashSessionId);
                $this->formOpen = false;
                $this->dispatch('cash-session-closed');
            }
        });
    }

    public function showClosing(string $sessionId): void
    {
        $actor = $this->actor();
        $this->attempt(function () use ($actor, $sessionId): void {
            // Consultar historial no cambia la intención actual ni su UUID.
            $this->receipt = app(CashClosings::class)->reconciliation($actor, $sessionId);
        });
    }

    public function filterHistory(): void
    {
        $this->actor();
        $this->page = 1;
    }

    public function changePage(int $page): void
    {
        $this->actor();
        $this->page = max(1, min(1000000, $page));
    }

    public function render(): View
    {
        $actor = $this->actor();
        $permissions = app(CashAccess::class)->require()->permissions;
        $canClose = in_array('cash.close', $permissions, true) && in_array('sales.view', $permissions, true);
        $filters = ['page' => $this->page, 'per_page' => 10, 'status' => $this->historyStatus];
        foreach (['date' => $this->date, 'closed_by' => $this->closedBy, 'register_id' => $this->registerId] as $field => $value) {
            if ($value !== '') {
                $filters[$field] = $value;
            }
        }
        $history = ['items' => [], 'total' => 0, 'page' => 1, 'perPage' => 10];
        // Mientras otro proceso trabaja, consultar status no espera el bloqueo financiero.
        if (! $this->pending) {
            try {
                $history = app(CashClosings::class)->history($actor, $filters);
            } catch (ValidationException $e) {
                foreach ($e->errors() as $field => $messages) {
                    $this->addError($field, $messages[0]);
                }
            }
        }

        return view('cash::livewire.closing-panel', compact('history', 'canClose'));
    }
}
