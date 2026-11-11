<?php

namespace Modules\Auth\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Modules\Auth\Application\EmployeeAccess;
use Modules\Auth\Application\SessionRegistry;
use Modules\Auth\Contracts\Authenticator;
use Modules\Auth\Domain\InvalidCredentials;
use Modules\Auth\Domain\InvalidPin;
use Modules\Auth\Http\InputMessages;
use Modules\Users\Contracts\IdentityAccess;

final class WebAuthenticationController
{
    public function login(Request $request, Authenticator $authentication): RedirectResponse
    {
        $data = $request->validate(['username' => ['required', 'string', 'max:64'], 'password' => ['required', 'string', 'max:72']], InputMessages::get());
        try {
            $user = $authentication->loginWeb($request, $data['username'], $data['password']);
        } catch (InvalidCredentials) {
            throw ValidationException::withMessages(['username' => ['No fue posible verificar las credenciales.']]);
        }

        return redirect()->route($user->mustChangePassword ? 'auth.password' : 'auth.account');
    }

    public function account(IdentityAccess $identities, SessionRegistry $sessions): View
    {
        $id = (string) Auth::guard('web')->id();

        return view('auth::account', ['identity' => $identities->inspect($id), 'sessions' => $sessions->listOwn($id)]);
    }

    public function logout(Request $request, Authenticator $authentication): RedirectResponse
    {
        $authentication->logoutWeb($request);

        return redirect()->route('login');
    }

    public function password(Request $request, Authenticator $authentication): RedirectResponse
    {
        $data = $request->validate(['current_password' => ['required', 'string', 'max:72'], 'password' => ['required', 'string', 'confirmed', 'max:72']], InputMessages::get());
        $authentication->changePassword($request, $data['current_password'], $data['password'], false);

        return redirect()->route('auth.account');
    }

    public function pin(Request $request, EmployeeAccess $employees): RedirectResponse
    {
        $data = $request->validate(['current_password' => ['required', 'string', 'max:72'], 'pin' => ['required', 'string', 'confirmed', 'regex:/\A[0-9]{4,6}\z/']], InputMessages::get());
        try {
            $employees->setPin($request, $data['current_password'], $data['pin']);
        } catch (InvalidCredentials) {
            throw ValidationException::withMessages(['current_password' => ['No fue posible verificar las credenciales.']]);
        }

        return back()->with('status', 'PIN actualizado.');
    }

    public function disablePin(Request $request, EmployeeAccess $employees): RedirectResponse
    {
        $data = $request->validate(['current_password' => ['required', 'string', 'max:72']], InputMessages::get());
        try {
            $employees->disablePin($request, $data['current_password']);
        } catch (InvalidCredentials) {
            throw ValidationException::withMessages(['current_password' => ['No fue posible verificar las credenciales.']]);
        }

        return back()->with('status', 'PIN deshabilitado.');
    }

    public function authorizeTerminal(Request $request, EmployeeAccess $employees): RedirectResponse
    {
        $data = $request->validate(['current_password' => ['required', 'string', 'max:72']], InputMessages::get());
        try {
            $employees->authorizeTerminal($request, $data['current_password']);
        } catch (InvalidCredentials) {
            throw ValidationException::withMessages(['current_password' => ['No fue posible verificar las credenciales.']]);
        }

        return back()->with('status', 'Sesión autorizada para cambio de empleado.');
    }

    public function switchEmployee(Request $request, EmployeeAccess $employees): RedirectResponse
    {
        $data = $request->validate(['username' => ['required', 'string', 'max:64'], 'pin' => ['required', 'string', 'regex:/\A[0-9]{4,6}\z/']], InputMessages::get());
        try {
            $employees->switchEmployee($request, $data['username'], $data['pin']);
        } catch (InvalidPin) {
            throw ValidationException::withMessages(['pin' => ['No fue posible verificar el PIN.']]);
        }

        return redirect()->route('auth.account');
    }

    public function revoke(Request $request, string $id, SessionRegistry $sessions): RedirectResponse
    {
        $sessions->revokeAuthorized((string) Auth::guard('web')->id(), $id);

        return redirect()->route('auth.account');
    }
}
