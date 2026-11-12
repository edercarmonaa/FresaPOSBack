<?php

namespace Modules\Auth\Policies;

use Modules\Users\Contracts\UserSnapshot;

final class SessionPolicy
{
    public function manage(UserSnapshot $actor, string $userId): bool
    {
        return $actor->isActive && ! $actor->mustChangePassword
            && ($actor->id === $userId || in_array('users.status', $actor->permissions, true));
    }
}
