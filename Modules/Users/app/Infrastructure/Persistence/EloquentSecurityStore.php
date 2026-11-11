<?php

namespace Modules\Users\Infrastructure\Persistence;

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Collection;
use LogicException;
use Modules\Users\Application\Ports\SecurityStore;
use Modules\Users\Infrastructure\Persistence\Models\User;

final class EloquentSecurityStore implements SecurityStore
{
    public function __construct(private readonly DatabaseManager $database) {}

    public function lock(): void
    {
        $connection = $this->database->connection();
        if ($connection->transactionLevel() < 1) {
            throw new LogicException('La escritura de seguridad requiere una transacción externa.');
        }
        if (! $connection->selectOne('SELECT id FROM user_security_locks WITH (UPDLOCK, HOLDLOCK) WHERE id = 1')) {
            throw new LogicException('Falta la fila de coordinación de seguridad.');
        }
    }

    public function user(string $id): User
    {
        return User::query()->findOrFail($id);
    }

    public function users(int $page): Collection
    {
        return User::query()->orderBy('id')->forPage(max(1, $page), 50)->get();
    }

    public function hasActiveAdministrator(): bool
    {
        return User::query()->where('is_active', true)->whereHas('roles', fn ($query) => $query->where('name', 'admin')->where('guard_name', 'web'))->exists();
    }
}
