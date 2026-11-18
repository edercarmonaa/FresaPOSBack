<?php

namespace Modules\Core\Infrastructure\Persistence;

use Illuminate\Database\DeadlockException;
use Illuminate\Database\QueryException;
use PDOException;
use Throwable;

final class SqlTransientErrors
{
    private function source(Throwable $exception): Throwable
    {
        // Laravel envuelve el error SQL al salir de transacciones anidadas.
        // Conservar el clasificador SQL, sin decidir por texto de excepciones.
        while ($exception instanceof DeadlockException && $exception->getPrevious() !== null) {
            $exception = $exception->getPrevious();
        }

        return $exception;
    }

    public function code(Throwable $exception): int
    {
        return (int) (($this->source($exception)->errorInfo ?? [])[1] ?? 0);
    }

    public function reconnect(Throwable $exception): bool
    {
        return ! in_array($this->code($exception), [1205, 1222, 40501, 10928, 10929], true);
    }

    public function recoverable(Throwable $exception): bool
    {
        $exception = $this->source($exception);
        if (! $exception instanceof QueryException && ! $exception instanceof PDOException) {
            return false;
        }
        $info = $exception->errorInfo ?? [];

        return in_array((int) ($info[1] ?? 0), [1205, 1222, 40197, 40501, 40613, 10928, 10929, 49918, 49919, 49920], true)
            || in_array((string) ($info[0] ?? $exception->getCode()), ['08S01', '08001', '08007', 'HYT00', 'HYT01'], true);
    }
}
