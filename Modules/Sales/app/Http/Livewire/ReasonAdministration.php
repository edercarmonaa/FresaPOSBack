<?php

namespace Modules\Sales\Http\Livewire;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Modules\Core\Contracts\IdempotentOperations;
use Modules\Core\Contracts\OperationIntent;
use Modules\Core\Contracts\OperationRejected;
use Modules\Sales\Contracts\SaleOperationReasons;
use Modules\Sales\Domain\ReasonInput;
use Modules\Sales\Http\PosAccess;

class ReasonAdministration extends Component
{
    #[Locked]
    public string $actorContext = '';

    #[Locked]
    public ?string $reasonId = null;

    #[Locked]
    public string $revision = '';

    #[Locked]
    public string $key = '';

    #[Locked]
    public string $action = 'create';

    #[Locked]
    public array $frozen = [];

    #[Locked]
    public bool $submitted = false;

    public bool $formOpen = false;

    public string $code = '';

    public string $operation = 'sale_item_removal';

    public string $name = '';

    public string $description = '';

    public int $displayOrder = 0;

    public string $filterOperation = '';

    public string $filterActive = '';

    public int $page = 1;

    public string $status = '';

    private bool $written = false;

    private function actor(): string
    {
        $actor = app(PosAccess::class)->require('settings.update');
        abort_if($this->actorContext !== '' && $actor->id !== $this->actorContext, 403);

        return $actor->id;
    }

    private function path(): string
    {
        return 'sales_reason_admin.'.OperationIntent::context($this->actor());
    }

    public function mount(): void
    {
        $this->actorContext = $this->actor();
        $intent = session()->get($this->path());
        if (is_array($intent)) {
            [$this->key, $this->reasonId, $this->revision, $this->action, $this->frozen] = $intent;
            $this->submitted = true;
            $this->formOpen = true;
            $this->queryOperation();
        }
    }

    private function prepare(string $action, ?array $reason = null): void
    {
        $this->actor();
        if ($this->submitted && in_array(app(IdempotentOperations::class)->status(OperationIntent::context($this->actor()), $this->key)->state, ['pending', 'not_found'], true)) {
            $this->addError('operation', 'La intención sigue pendiente. Consulta su estado.');

            return;
        }
        $this->resetErrorBag();
        $this->action = $action;
        $this->reasonId = $reason['id'] ?? null;
        $this->revision = $reason['revision'] ?? '';
        $this->key = (string) Str::uuid();
        $this->frozen = [];
        $this->submitted = false;
        $this->code = $reason['code'] ?? '';
        $this->operation = $reason['operation'] ?? 'sale_item_removal';
        $this->name = $reason['name'] ?? '';
        $this->description = $reason['description'] ?? '';
        $this->displayOrder = $reason['display_order'] ?? 0;
        $this->formOpen = true;
        session()->forget($this->path());
    }

    public function create(): void
    {
        $this->prepare('create');
    }

    public function edit(string $id): void
    {
        $this->prepare('update', app(SaleOperationReasons::class)->find($this->actor(), $id));
    }

    public function changeStatus(string $id): void
    {
        $reason = app(SaleOperationReasons::class)->find($this->actor(), $id);
        $this->prepare('status', $reason);
        if (! $this->submitted) {
            $this->frozen = ['is_active' => ! $reason['is_active']];
        }
    }

    public function save(): void
    {
        $actor = $this->actor();
        if ($this->written) {
            return;
        }
        $this->resetErrorBag();
        try {
            if (! $this->submitted) {
                $input = $this->action === 'status' ? $this->frozen : ['name' => $this->name, 'description' => $this->description,
                    'display_order' => $this->displayOrder, ...($this->action === 'create' ? ['code' => $this->code, 'operation' => $this->operation] : [])];
                $this->frozen = ReasonInput::values($this->action, $input);
                $this->submitted = true;
            }
            session()->put($this->path(), [$this->key, $this->reasonId, $this->revision, $this->action, $this->frozen]);
            session()->save();
            $this->written = true;
            app(SaleOperationReasons::class)->write($actor, $this->action, $this->frozen, $this->key, $this->reasonId, $this->revision === '' ? null : '"'.$this->revision.'"');
            $this->formOpen = false;
            $this->status = 'Motivo guardado. La historia de operaciones anteriores se conserva.';
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                $this->addError($field, $messages[0]);
            }
        } catch (OperationRejected $exception) {
            $this->addError('operation', $exception->getMessage().' Consulta el estado antes de preparar otra intención.');
        } catch (\Throwable $exception) {
            if ($exception instanceof AuthorizationException) {
                throw $exception;
            }
            report($exception);
            $this->addError('operation', 'Resultado no confirmado. Conservamos la intención; consulta su estado o reintenta la misma solicitud.');
        }
    }

    public function queryOperation(): void
    {
        $actor = $this->actor();
        if ($this->key === '') {
            return;
        }
        $state = app(IdempotentOperations::class)->status(OperationIntent::context($actor), $this->key);
        $this->status = match ($state->state) {
            'completed' => 'La operación ya se confirmó; no se ejecutó de nuevo.',
            'failed' => 'La operación fue rechazada. Prepara explícitamente otra intención.',
            'pending' => 'La operación sigue en proceso. Conservamos su clave.',
            default => 'No hay un resultado confirmado. Reintenta conservando la misma intención.',
        };
        if ($state->state === 'completed') {
            $this->formOpen = false;
        }
    }

    public function render(): View
    {
        $filters = ['administration' => true, 'page' => $this->page, 'per_page' => 10];
        if ($this->filterOperation !== '') {
            $filters['operation'] = $this->filterOperation;
        }
        if ($this->filterActive !== '') {
            abort_unless(in_array($this->filterActive, ['active', 'inactive'], true), 422);
            $filters['is_active'] = $this->filterActive === 'active';
        }

        return view('sales::livewire.reason-administration', ['reasons' => app(SaleOperationReasons::class)->search($this->actor(), $filters)]);
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['filterOperation', 'filterActive'], true)) {
            $this->page = 1;
        }
    }
}
