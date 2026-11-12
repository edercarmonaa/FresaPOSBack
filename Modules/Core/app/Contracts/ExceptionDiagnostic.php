<?php

namespace Modules\Core\Contracts;

use Illuminate\Database\QueryException;
use Throwable;

final class ExceptionDiagnostic
{
    /** @return array<string,mixed> */
    public static function context(Throwable $exception): array
    {
        if (! $exception instanceof QueryException) {
            return ['exception' => $exception];
        }
        // QueryException incorpora SQL y bindings en su mensaje; el driver también
        // puede repetir valores rechazados. Conservar diagnóstico sin esos textos.
        $state = $exception->errorInfo[0] ?? null;

        return [
            'exception_type' => $exception::class,
            'sqlstate' => is_string($state) && preg_match('/\A[A-Z0-9]{5}\z/', $state) === 1 ? $state : null,
            'driver_code' => (int) ($exception->errorInfo[1] ?? 0),
            'source_file' => $exception->getFile(),
            'source_line' => $exception->getLine(),
        ];
    }
}
