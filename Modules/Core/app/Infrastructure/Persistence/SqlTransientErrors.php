<?php

namespace Modules\Core\Infrastructure\Persistence;

use Illuminate\Database\QueryException;
use PDOException;
use Throwable;

final class SqlTransientErrors
{
    public function reconnect(Throwable $exception): bool
    {
        return ! in_array((int) (($exception->errorInfo ?? [])[1] ?? 0), [1205, 1222, 40501, 10928, 10929], true);
    }

    public function recoverable(Throwable $exception): bool
    {
        if (! $exception instanceof QueryException && ! $exception instanceof PDOException) {
            return false;
        }
        $info = $exception->errorInfo ?? [];

        return in_array((int) ($info[1] ?? 0), [1205, 1222, 40197, 40501, 40613, 10928, 10929, 49918, 49919, 49920], true)
            || in_array((string) ($info[0] ?? $exception->getCode()), ['08S01', '08001', '08007', 'HYT00', 'HYT01'], true);
    }
}
