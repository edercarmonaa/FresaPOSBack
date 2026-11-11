<?php

namespace Modules\Core\Infrastructure\Persistence;

use Closure;
use Illuminate\Database\DatabaseManager;
use LogicException;
use Modules\Core\Contracts\TransactionManager;

final class LaravelTransactionManager implements TransactionManager
{
    public function __construct(private readonly DatabaseManager $database) {}

    public function run(Closure $operation): mixed
    {
        // Una conexión y un intento. Los reintentos requieren idempotencia explícita.
        return $this->database->connection()->transaction(fn () => $operation(), 1);
    }

    public function afterCommit(Closure $callback): void
    {
        $connection = $this->database->connection();
        if ($connection->transactionLevel() < 1) {
            throw new LogicException('afterCommit debe registrarse dentro de una transacción activa.');
        }

        $connection->afterCommit($callback);
    }
}
