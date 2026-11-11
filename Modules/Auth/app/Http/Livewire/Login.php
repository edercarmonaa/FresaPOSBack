<?php

namespace Modules\Auth\Http\Livewire;

use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Auth\Contracts\Authenticator;
use Modules\Auth\Domain\InvalidCredentials;
use Modules\Auth\Http\InputMessages;

class Login extends Component
{
    public string $username = '';

    public string $password = '';

    public function login(Authenticator $authentication): mixed
    {
        try {
            $this->validate(['username' => ['required', 'string', 'max:64'], 'password' => ['required', 'string', 'max:72']], InputMessages::get());
            $user = $authentication->loginWeb(request(), $this->username, $this->password);

            return $this->redirectRoute($user->mustChangePassword ? 'auth.password' : 'auth.account');
        } catch (InvalidCredentials) {
            $this->addError('username', 'No fue posible verificar las credenciales.');
        } finally {
            $this->password = '';
        }

        return null;
    }

    public function render(): View
    {
        return view('auth::livewire-login');
    }
}
