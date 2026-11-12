<?php

namespace Modules\Catalog\Policies;

use Modules\Users\Contracts\UserSnapshot;

final class CatalogPolicy
{
    public function allows(UserSnapshot $actor, string $permission): bool
    {
        return $actor->isActive && ! $actor->mustChangePassword && in_array($permission, $actor->permissions, true);
    }
}
