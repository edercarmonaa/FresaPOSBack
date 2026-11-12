<?php

namespace Modules\Auth\Application;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Timebox;
use Modules\Auth\Domain\InvalidCredentials;
use Modules\Auth\Domain\InvalidPin;
use Modules\Auth\Infrastructure\Models\EmployeePin;
use Modules\Core\Contracts\SecurityEvent;
use Modules\Core\Contracts\TransactionManager;
use Modules\Users\Contracts\IdentityAccess;
use Modules\Users\Contracts\UserSnapshot;

final class EmployeeAccess
{
    public function __construct(private readonly IdentityAccess $identities, private readonly SessionRegistry $sessions, private readonly TransactionManager $transactions, private readonly AttemptGuard $attempts) {}

    private function reauthenticate(Request $request, #[\SensitiveParameter] string $password): UserSnapshot
    {
        $this->sessions->web($request);
        $user = $this->identities->inspect((string) Auth::guard('web')->id());
        $key = $this->attempts->key('reauth', (string) Auth::guard('web')->id(), (string) $request->ip());

        return $this->attempts->run($key, function () use ($key, $user, $password): UserSnapshot {
            $this->attempts->check($key, $user?->id);
            if (! $user || $user->mustChangePassword || ! $this->identities->verify($user->username, $password)) {
                $this->attempts->failed($key, 'password', $user?->id, $user?->id);
                throw new InvalidCredentials;
            }
            $this->attempts->clear($key);

            return $user;
        });
    }

    public function setPin(Request $request, #[\SensitiveParameter] string $password, #[\SensitiveParameter] string $pin): void
    {
        $user = $this->reauthenticate($request, $password);
        Validator::make(['pin' => $pin], ['pin' => ['required', 'string', 'regex:/\A[0-9]{4,6}\z/']], ['pin.regex' => 'El PIN debe contener de 4 a 6 dígitos.'])->validate();
        $this->transactions->run(function () use ($request, $user, $pin): void {
            $this->sessions->lock();
            $this->sessions->web($request);
            EmployeePin::query()->updateOrCreate(['user_id' => $user->id], ['pin_hash' => Hash::make($pin)]);
            event(new SecurityEvent('pin.changed', $user->id, $user->id));
        });
    }

    public function disablePin(Request $request, #[\SensitiveParameter] string $password): void
    {
        $user = $this->reauthenticate($request, $password);
        $this->transactions->run(function () use ($request, $user): void {
            $this->sessions->lock();
            $this->sessions->web($request);
            EmployeePin::query()->whereKey($user->id)->delete();
            event(new SecurityEvent('pin.disabled', $user->id, $user->id));
        });
    }

    public function authorizeTerminal(Request $request, #[\SensitiveParameter] string $password): void
    {
        $user = $this->reauthenticate($request, $password);
        abort_unless(in_array('cash.open', $user->permissions, true), 403);
        $this->transactions->run(function () use ($request, $user): void {
            $this->sessions->lock();
            $this->sessions->web($request)->update(['terminal_owner_id' => $user->id]);
        });
    }

    public function switchEmployee(Request $request, string $username, #[\SensitiveParameter] string $pin): UserSnapshot
    {
        $current = $this->sessions->web($request);
        $owner = $current->terminal_owner_id ? $this->identities->inspect($current->terminal_owner_id) : null;
        abort_unless($owner && in_array('cash.open', $owner->permissions, true), 403);
        $key = $this->attempts->key('pin', $username, (string) $request->ip());

        return $this->attempts->run($key, function () use ($request, $current, $username, $pin, $key): UserSnapshot {
            $this->attempts->check($key, $current->user_id);
            // La identidad se busca a través de un contrato; Auth no importa modelos de Users.
            $target = $this->identities->byUsername($username);
            $row = $target ? EmployeePin::query()->find($target->id) : null;
            $matches = (new Timebox)->call(function () use ($row, $pin): bool {
                $valid = preg_match('/\A[0-9]{4,6}\z/', $pin) === 1;
                $matches = Hash::check($valid ? $pin : 'invalid-pin', $row->pin_hash ?? '$2y$12$goDt1fVqJ2cvE9LKb0fcJOP8GDnyWZh4JQFQ3vVZY7gR54CdA9E8G');

                return $valid && $row && $matches;
            }, 250000);
            if (! $matches || ! $target || $target->mustChangePassword) {
                $this->attempts->failed($key, 'pin', $current->user_id);
                throw new InvalidPin;
            }
            $this->assertEmployeeScope($target);
            $this->attempts->clear($key);

            return $this->transactions->run(function () use ($request, $current, $target, $pin): UserSnapshot {
                $this->sessions->lock();
                $this->sessions->web($request);
                $owner = $this->identities->inspect($current->terminal_owner_id);
                abort_unless($owner && in_array('cash.open', $owner->permissions, true), 403);
                $target = $this->identities->inspect($target->id) ?? throw new InvalidPin;
                $this->assertEmployeeScope($target);
                $freshPin = EmployeePin::query()->find($target->id);
                if (! $freshPin || ! Hash::check($pin, $freshPin->pin_hash)) {
                    throw new InvalidPin;
                }
                $this->sessions->revoke($current, $current->user_id);
                $this->sessions->assertCapacity($target->id, 'web');
                if (! Auth::guard('web')->loginUsingId($target->id, false)) {
                    throw new InvalidPin;
                }
                $request->session()->regenerate(true);
                $request->session()->regenerateToken();
                $this->sessions->createWeb($request, $target, $current->terminal_owner_id, 'pin');

                return $target;
            });
        });
    }

    private function assertEmployeeScope(UserSnapshot $target): void
    {
        if ($target->mustChangePassword) {
            throw new InvalidPin;
        }
        // Un PIN nunca eleva a administración; se exige contraseña completa para ese contexto.
        abort_if(count(array_filter($target->permissions, fn (string $permission) => str_starts_with($permission, 'users.') || str_starts_with($permission, 'roles.'))) > 0, 403);
        abort_unless(in_array('sales.create', $target->permissions, true) || in_array('cash.open', $target->permissions, true), 403);
    }
}
