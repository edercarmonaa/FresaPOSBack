<?php

namespace Modules\Users\Application\Services;

use Closure;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Modules\Core\Contracts\TransactionManager;
use Modules\Users\Application\PasswordHistoryService;
use Modules\Users\Application\Ports\SecurityStore;
use Modules\Users\Contracts\UserAccessChanged;
use Modules\Users\Contracts\UserAdministration;
use Modules\Users\Contracts\UserSnapshot;
use Modules\Users\Domain\SecurityConflict;
use Modules\Users\Domain\Username;
use Modules\Users\Infrastructure\Persistence\Models\Permission;
use Modules\Users\Infrastructure\Persistence\Models\Role;
use Modules\Users\Infrastructure\Persistence\Models\User;
use Spatie\Permission\PermissionRegistrar;

final class ManageUsers implements UserAdministration
{
    public function __construct(
        private readonly TransactionManager $transactions,
        private readonly SecurityStore $store,
        private readonly PasswordHistoryService $passwords,
    ) {}

    public function create(string $actorId, #[\SensitiveParameter] array $data): UserSnapshot
    {
        return $this->write(function () use ($actorId, $data): UserSnapshot {
            $this->authorize($actorId, 'create');
            $attributes = $this->validateProfile($data);
            Validator::make($data, ['password' => ['required', 'string']], ['password.required' => 'La contraseña es obligatoria.'])->validate();
            $user = new User($attributes);
            $user->is_active = true;
            $user->must_change_password = true;
            $this->passwords->replace($user, $data['password']);

            return $this->snapshot($user);
        });
    }

    public function find(string $actorId, string $userId): UserSnapshot
    {
        $user = $this->store->user($userId);
        $this->authorize($actorId, 'view', $user);

        return $this->snapshot($user);
    }

    public function list(string $actorId, int $page = 1): array
    {
        $this->authorize($actorId, 'viewAny');

        return $this->store->users($page)->map(fn (User $user) => $this->snapshot($user))->all();
    }

    public function update(string $actorId, string $userId, array $data): UserSnapshot
    {
        return $this->write(function () use ($actorId, $userId, $data): UserSnapshot {
            $user = $this->store->user($userId);
            $this->authorize($actorId, 'update', $user);
            $user->fill($this->validateProfile($data, $user));
            $user->save();

            return $this->snapshot($user);
        });
    }

    public function setActive(string $actorId, string $userId, bool $active): UserSnapshot
    {
        return $this->write(function () use ($actorId, $userId, $active): UserSnapshot {
            $user = $this->store->user($userId);
            $this->authorize($actorId, 'setActive', $user);
            $user->is_active = $active;
            $user->save();
            $this->assertAdministratorRemains();
            $this->transactions->afterCommit(fn () => event(new UserAccessChanged($userId)));

            return $this->snapshot($user);
        });
    }

    public function assignRole(string $actorId, string $userId, string $role): UserSnapshot
    {
        return $this->changeRole($actorId, $userId, $role, true);
    }

    public function removeRole(string $actorId, string $userId, string $role): UserSnapshot
    {
        return $this->changeRole($actorId, $userId, $role, false);
    }

    private function changeRole(string $actorId, string $userId, string $role, bool $assign): UserSnapshot
    {
        $role = strtolower(trim($role));

        return $this->write(function () use ($actorId, $userId, $role, $assign): UserSnapshot {
            $user = $this->store->user($userId);
            $this->authorize($actorId, 'assignRoles', $user);
            $record = Role::query()->where('name', $role)->where('guard_name', 'web')->firstOrFail();
            if ($assign) {
                $user->assignRole($record);
            } else {
                $user->removeRole($record);
            }
            $this->assertAdministratorRemains();

            return $this->snapshot($user);
        });
    }

    public function delete(string $actorId, string $userId): void
    {
        $this->write(function () use ($actorId, $userId): void {
            $user = $this->store->user($userId);
            $this->authorize($actorId, 'delete', $user);
            $user->delete();
            $this->assertAdministratorRemains();
            $this->transactions->afterCommit(fn () => event(new UserAccessChanged($userId)));
        });
    }

    public function permissions(string $actorId, string $userId): array
    {
        return $this->find($actorId, $userId)->permissions;
    }

    public function roles(string $actorId): array
    {
        $this->authorize($actorId, 'viewRoles');

        return Role::query()->where('guard_name', 'web')->orderBy('name')->get()->map(fn (Role $role) => ['name' => $role->name, 'label' => $role->label])->all();
    }

    public function createRole(string $actorId, string $name, string $label): void
    {
        $this->write(function () use ($actorId, $name, $label): void {
            $this->authorize($actorId, 'managePermissions');
            $attributes = Validator::make(['name' => $name, 'label' => trim($label)], [
                'name' => ['required', 'string', 'regex:/\A[a-z][a-z0-9_-]{1,63}\z/', Rule::unique('roles')->where('guard_name', 'web')],
                'label' => ['required', 'string', 'max:100'],
            ])->validate();
            Role::query()->create($attributes + ['guard_name' => 'web']);
        });
    }

    public function setRolePermissions(string $actorId, string $role, array $permissions): void
    {
        $role = strtolower(trim($role));

        $this->write(function () use ($actorId, $role, $permissions): void {
            $this->authorize($actorId, 'managePermissions');
            $record = Role::query()->where('name', $role)->where('guard_name', 'web')->firstOrFail();
            Validator::make(['permissions' => $permissions], ['permissions' => ['array'], 'permissions.*' => ['required', 'string', 'distinct', Rule::exists('permissions', 'name')->where('guard_name', 'web')]])->validate();
            if ($record->name === 'admin' && array_diff(config('users.security.protected_admin_permissions'), $permissions) !== []) {
                throw new SecurityConflict('El rol administrador debe conservar la capacidad de administrar seguridad.');
            }
            $records = Permission::query()->where('guard_name', 'web')->whereIn('name', $permissions)->get();
            $record->syncPermissions($records);
            $this->assertAdministratorRemains();
        });
    }

    private function authorize(string $actorId, string $ability, ?User $target = null): void
    {
        $actor = $this->store->user($actorId);
        Gate::forUser($actor)->authorize($ability, $target ?? User::class);
    }

    private function assertAdministratorRemains(): void
    {
        if (! $this->store->hasActiveAdministrator()) {
            throw new SecurityConflict('La operación dejaría el sistema sin administrador activo.');
        }
    }

    private function write(Closure $operation): mixed
    {
        return $this->transactions->run(function () use ($operation): mixed {
            $this->store->lock();
            // Evita decidir con un caché de permisos previo a otra operación confirmada.
            app(PermissionRegistrar::class)->forgetCachedPermissions();
            $result = $operation();
            $this->transactions->afterCommit(fn () => app(PermissionRegistrar::class)->forgetCachedPermissions());

            return $result;
        });
    }

    /** @return array{name:string,username:string} */
    private function validateProfile(array $data, ?User $user = null): array
    {
        $values = ['name' => $data['name'] ?? $user?->name, 'username' => $data['username'] ?? $user?->username];
        if (is_string($values['name'])) {
            $values['name'] = trim($values['name']);
        }
        if (is_string($values['username'])) {
            $values['username'] = Username::normalize($values['username']);
        }

        return Validator::make($values, [
            'name' => ['required', 'string', 'max:150'],
            'username' => ['required', 'string', 'regex:/\A[a-z0-9._-]{3,64}\z/', Rule::unique('users', 'username')->ignore($user?->getKey())],
        ], [
            'name.required' => 'El nombre es obligatorio.', 'name.max' => 'El nombre no debe superar 150 caracteres.',
            'username.required' => 'El nombre de usuario es obligatorio.', 'username.regex' => 'Utiliza entre 3 y 64 letras minúsculas, números, puntos, guiones o guiones bajos.',
            'username.unique' => 'El nombre de usuario ya está reservado.',
        ])->validate();
    }

    private function snapshot(User $user): UserSnapshot
    {
        $user->unsetRelation('roles')->unsetRelation('permissions');

        return new UserSnapshot($user->id, $user->name, $user->username, $user->is_active, $user->must_change_password,
            $user->getRoleNames()->sort()->values()->all(), $user->is_active ? $user->getAllPermissions()->pluck('name')->sort()->values()->all() : []);
    }
}
