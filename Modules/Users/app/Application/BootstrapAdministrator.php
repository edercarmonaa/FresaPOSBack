<?php

namespace Modules\Users\Application;

use Illuminate\Support\Facades\Validator;
use Modules\Core\Contracts\SecurityEvent;
use Modules\Core\Contracts\TransactionManager;
use Modules\Users\Application\Ports\SecurityStore;
use Modules\Users\Domain\SecurityConflict;
use Modules\Users\Domain\Username;
use Modules\Users\Infrastructure\Persistence\Models\Role;
use Modules\Users\Infrastructure\Persistence\Models\User;

// Aprovisionamiento inicial exclusivo de consola; no es autenticación ni recuperación.
final class BootstrapAdministrator
{
    public function __construct(private readonly TransactionManager $transactions, private readonly SecurityStore $store, private readonly PasswordHistoryService $passwords) {}

    public function create(string $name, string $username, #[\SensitiveParameter] string $password): string
    {
        return $this->transactions->run(function () use ($name, $username, $password): string {
            $this->store->lock();
            if (User::withTrashed()->exists()) {
                throw new SecurityConflict('El aprovisionamiento inicial solo está permitido cuando nunca se han creado usuarios.');
            }
            $role = Role::query()->where('name', 'admin')->where('guard_name', 'web')->firstOrFail();
            $required = config('users.security.protected_admin_permissions');
            if (array_diff($required, $role->permissions->pluck('name')->all()) !== []) {
                throw new SecurityConflict('Inicializa y revisa los permisos administrativos antes del aprovisionamiento.');
            }
            $data = Validator::make(['name' => trim($name), 'username' => Username::normalize($username)], [
                'name' => ['required', 'string', 'max:150'], 'username' => ['required', 'string', 'regex:/\A[a-z0-9._-]{3,64}\z/'],
            ])->validate();
            $user = new User($data);
            $user->is_active = true;
            $user->must_change_password = true;
            $this->passwords->replace($user, $password);
            $user->assignRole($role);
            event(new SecurityEvent('administrator.provisioned', $user->id, $user->id));

            return $user->id;
        });
    }
}
