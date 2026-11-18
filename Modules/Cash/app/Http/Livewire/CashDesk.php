<?php

namespace Modules\Cash\Http\Livewire;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use Modules\Cash\Contracts\CashConflict;
use Modules\Cash\Contracts\CashOperations;
use Modules\Cash\Http\CashAccess;
use Modules\Cash\Http\Resources\SessionResource;
use Modules\Users\Contracts\IdentityAccess;

final class CashDesk extends Component
{
    #[Locked]
    public string $actorContext;

    #[Locked]
    public string $openingKey;

    public string $openingFund = '0.0000';

    #[Locked]
    public string $status = '';

    #[Locked]
    public bool $counting = false;

    #[On('cash-count-started')]
    public function beginBlindCount(): void
    {
        $this->actor('cash.close');
        $this->counting = true;
    }

    #[On('cash-session-closed')]
    public function closingConfirmed(): void
    {
        $this->actor();
        $this->counting = false;
        $this->openingKey = strtolower((string) Str::ulid());
        $this->status = 'Estado actualizado. Para otro turno registra una nueva apertura expresamente.';
    }

    public function mount(): void
    {
        $this->actorContext = app(CashAccess::class)->require()->id;
        $this->openingKey = strtolower((string) Str::ulid());
    }

    public function refreshState(): void
    {
        $this->actor();
        $this->resetErrorBag();
    }

    public function openSession(): void
    {
        $actor = $this->actor('cash.open');
        $this->resetErrorBag();
        try {
            $cash = app(CashOperations::class);
            $registers = $cash->registers($actor);
            if (count($registers) !== 1) {
                $this->addError('operation', 'La caja requiere aprovisionamiento administrativo.');

                return;
            }
            $session = $cash->open($actor, $registers[0]->id, $this->openingFund, $this->openingKey);
            $this->openingFund = $session->openingFund;
            $this->status = 'Sesión de caja abierta correctamente.';
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $key => $messages) {
                $this->addError($key, $messages[0]);
            }
        } catch (CashConflict $exception) {
            $this->addError('operation', $exception->getMessage());
        }
    }

    private function actor(string $permission = 'cash.view'): string
    {
        $actor = app(CashAccess::class)->require($permission);
        abort_unless($actor->id === $this->actorContext, 403);

        return $actor->id;
    }

    public function render(): View
    {
        $actor = $this->actor();
        $cash = app(CashOperations::class);
        $registers = $cash->registers($actor);
        $session = $cash->current($actor);
        $responsible = $session ? app(IdentityAccess::class)->inspect($session->openedBy)?->name : null;
        $canOpen = in_array('cash.open', app(CashAccess::class)->require()->permissions, true);
        $session = $session ? (new SessionResource($session))->resolve(request()) : null;

        $canViewClosings = in_array('cash.closings.view', app(CashAccess::class)->require()->permissions, true);

        return view('cash::livewire.cash-desk', compact('registers', 'session', 'responsible', 'canOpen', 'canViewClosings'));
    }
}
