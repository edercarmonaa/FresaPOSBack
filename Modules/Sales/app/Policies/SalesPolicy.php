<?php

namespace Modules\Sales\Policies;

use Modules\Users\Contracts\UserSnapshot;

final class SalesPolicy
{
    public function allows(UserSnapshot $actor, string $permission): bool
    {
        return $actor->isActive && ! $actor->mustChangePassword && in_array($permission, $actor->permissions, true);
    }
}
