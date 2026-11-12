<?php

namespace Modules\Auth\Http\Livewire;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Modules\Auth\Application\SessionRegistry;
use Modules\Users\Contracts\IdentityAccess;

class Sessions extends Component
{
    public string $userId = '';

    public string $status = '';

    #[Locked]
    public string $selectedUserId = '';

    public function consult(SessionRegistry $sessions): void
    {
        $this->validate(['userId' => ['nullable', 'ulid']]);
        $target = $this->userId === '' ? $this->actor() : $this->userId;
        $sessions->listAuthorized($this->actor(), $target);
        $this->selectedUserId = $target;
        $this->status = '';
    }

    public function revoke(string $id, SessionRegistry $sessions): void
    {
        validator(['id' => $id], ['id' => ['required', 'ulid']])->validate();
        $sessions->revokeAuthorized($this->actor(), $id);
        $this->status = 'Acceso revocado.';
    }

    public function revokeAll(SessionRegistry $sessions): void
    {
        $sessions->revokeUser($this->actor(), $this->target());
        $this->status = 'Accesos revocados. Es necesario autenticarse nuevamente.';
    }

    public function revokeOthers(SessionRegistry $sessions): void
    {
        $sessions->revokeUser($this->actor(), $this->actor(), $sessions->web(request(), false)->id);
        $this->status = 'Los demás accesos fueron revocados.';
    }

    private function actor(): string
    {
        return (string) Auth::guard('web')->id();
    }

    private function target(): string
    {
        return $this->selectedUserId === '' ? $this->actor() : $this->selectedUserId;
    }

    public function render(): View
    {
        $target = $this->target();
        $sessions = app(SessionRegistry::class)->listAuthorized($this->actor(), $target);

        return view('auth::livewire-sessions', [
            'sessions' => $sessions,
            'identity' => app(IdentityAccess::class)->inspect($target),
        ]);
    }
}
