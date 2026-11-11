<?php

namespace Modules\Users\Policies;

use Modules\Users\Infrastructure\Persistence\Models\User;

final class UserPolicy
{
    private function allows(User $actor, string $permission): bool
    {
        return $actor->exists && ! $actor->trashed() && $actor->is_active && $actor->checkPermissionTo($permission, 'web');
    }

    public function viewAny(User $actor): bool
    {
        return $this->allows($actor, 'users.view');
    }

    public function view(User $actor, User $user): bool
    {
        return $this->allows($actor, 'users.view');
    }

    public function create(User $actor): bool
    {
        return $this->allows($actor, 'users.create');
    }

    public function update(User $actor, User $user): bool
    {
        return $this->allows($actor, 'users.update');
    }

    public function setActive(User $actor, User $user): bool
    {
        return $this->allows($actor, 'users.status');
    }

    public function assignRoles(User $actor, User $user): bool
    {
        return $this->allows($actor, 'users.roles.assign');
    }

    public function delete(User $actor, User $user): bool
    {
        return $this->allows($actor, 'users.delete');
    }

    public function viewRoles(User $actor): bool
    {
        return $this->allows($actor, 'roles.view');
    }

    public function managePermissions(User $actor): bool
    {
        return $this->allows($actor, 'roles.permissions.manage');
    }
}
