<?php

namespace Modules\Cash\Http\Livewire;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Modules\Cash\Contracts\CashMovements;
use Modules\Cash\Http\CashAccess;
use Modules\Core\Contracts\IdempotentOperations;
use Modules\Core\Contracts\OperationIntent;
use Modules\Core\Contracts\OperationRejected;

final class CashMovementsPanel extends Component
{
    #[Locked]
    public string $actorContext;

    #[Locked]
    public string $movementKey;

    #[Locked]
    public ?string $cashSessionId = null;

    #[Locked]
    public bool $resolved = false;

    #[Locked]
    public bool $pending = false;

    #[Locked]
    public string $status = '';

    public string $movementType = 'cash_in';

    public string $amount = '';

    public string $concept = '';

    public int $page = 1;

    private bool $submitted = false;

    private function path(): string
    {
        return 'cash_movement_intent.'.OperationIntent::context($this->actorContext);
    }

    private function actor(string $permission = 'cash.view'): string
    {
        $actor = app(CashAccess::class)->require($permission);
        abort_unless($actor->id === $this->actorContext, 403);

        return $actor->id;
    }

    public function mount(): void
    {
        $this->actorContext = app(CashAccess::class)->require()->id;
        $this->movementKey = session()->get($this->path(), (string) Str::uuid());
        session()->put($this->path(), $this->movementKey);
        $this->resolveMovement();
    }

    public function resolveMovement(): void
    {
        $actor = $this->actor();
        $state = app(IdempotentOperations::class)->status(OperationIntent::context($actor), $this->movementKey);
        $this->pending = $state->state === 'pending';
        $this->resolved = in_array($state->state, ['completed', 'failed'], true);
        if ($this->pending) {
            $this->status = 'La operación sigue en proceso. Consulta su estado antes de preparar otra.';
        } elseif ($this->resolved) {
            $this->status = $state->state === 'completed' ? 'Operación confirmada. Consulta su movimiento en el historial antes de preparar otra.' : 'La intención fue rechazada. Puedes preparar otra operación explícitamente.';
        }
    }

    public function prepareMovement(): void
    {
        $this->actor('cash.movements.create');
        $this->resolveMovement();
        if ($this->pending) {
            return;
        }
        $this->movementKey = (string) Str::uuid();
        session()->put($this->path(), $this->movementKey);
        session()->save();
        $this->resolved = false;
        $this->cashSessionId = null;
        $this->resetErrorBag();
        $this->amount = '';
        $this->concept = '';
        $this->status = 'Nueva intención preparada. Verifica el tipo, importe y concepto.';
    }

    public function recordMovement(): void
    {
        $actor = $this->actor('cash.movements.create');
        if ($this->submitted) {
            return;
        }$this->submitted = true;
        $this->resetErrorBag();
        // La metadata del UUID se conserva antes de cualquier posible commit.
        session()->put($this->path(), $this->movementKey);
        session()->save();
        try {
            if ($this->cashSessionId === null) {
                $this->addError('operation', 'No hay sesión de caja abierta.');

                return;
            }
            $movement = app(CashMovements::class)->record($actor, $this->cashSessionId, $this->movementType, $this->amount, $this->concept, $this->movementKey);
            $this->resolved = true;
            $this->status = 'Movimiento confirmado: '.$movement->id.'. No se duplicará al reintentar.';
            $this->page = 1;
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                $this->addError($field, $messages[0]);
            }
        } catch (OperationRejected $exception) {
            if ($exception->errors !== []) {
                foreach ($exception->errors as $field => $messages) {
                    $this->addError($field, $messages[0]);
                }
            } else {
                $this->addError('operation', $exception->getMessage());
            }
            $this->resolveMovement();
        }
    }

    public function changePage(int $page): void
    {
        $this->actor();
        $this->page = max(1, min(1000000, $page));
    }

    public function render(): View
    {
        $actor = $this->actor();
        $canMove = in_array('cash.movements.create', app(CashAccess::class)->require()->permissions, true);
        $history = $this->pending ? ['items' => [], 'balance' => null, 'page' => 1, 'per_page' => 10, 'total' => 0] : app(CashMovements::class)->search($actor, ['page' => $this->page, 'per_page' => 10]);
        if ($this->cashSessionId === null && ! $this->resolved && $history['balance'] !== null) {
            $this->cashSessionId = $history['balance']['cash_session_id'];
        }

        return view('cash::livewire.movements-panel', compact('history', 'canMove'));
    }
}
