<?php

namespace Modules\Users\Infrastructure\Persistence;

use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Contracts\Auth\Authenticatable;
use Modules\Users\Infrastructure\Persistence\Models\User;

final class ActiveUserProvider extends EloquentUserProvider
{
    public function retrieveById($identifier)
    {
        $user = parent::retrieveById($identifier);

        return $user instanceof User && $user->is_active && ! $user->trashed() ? $user : null;
    }

    public function retrieveByToken($identifier, #[\SensitiveParameter] $token)
    {
        $user = parent::retrieveByToken($identifier, $token);

        return $user instanceof User && $user->is_active && ! $user->trashed() ? $user : null;
    }

    public function validateCredentials(Authenticatable $user, #[\SensitiveParameter] array $credentials)
    {
        $matches = parent::validateCredentials($user, $credentials);

        return $matches && $user instanceof User && $user->is_active && ! $user->trashed();
    }
}
