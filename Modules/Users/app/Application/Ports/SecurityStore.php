<?php

namespace Modules\Users\Application\Ports;

use Illuminate\Database\Eloquent\Collection;
use Modules\Users\Infrastructure\Persistence\Models\User;

// Puerto interno del módulo; los modelos nunca salen por sus contratos públicos.
interface SecurityStore
{
    public function lock(): void;

    public function user(string $id): User;

    /** @return Collection<int,User> */
    public function users(int $page): Collection;

    public function hasActiveAdministrator(): bool;
}
