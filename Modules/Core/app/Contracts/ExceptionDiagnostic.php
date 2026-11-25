<?php

namespace Modules\Core\Contracts;

use Illuminate\Database\QueryException;
use Throwable;

final class ExceptionDiagnostic
{
    /** @return array<string,mixed> */
    public static function context(Throwable $exception): array
    {
        // Nunca entregar Throwable al logger: sus mensajes, causas y argumentos
        // pueden contener credenciales o SQL, incluso si la causa está envuelta.
        $context = [
            'exception_type' => $exception::class,
            'source_file' => $exception->getFile(),
            'source_line' => $exception->getLine(),
        ];
        $cause = $exception;
        while ($cause !== null) {
            if ($cause instanceof QueryException) {
                $state = $cause->errorInfo[0] ?? null;
                $context['sqlstate'] = is_string($state) && preg_match('/\A[A-Z0-9]{5}\z/', $state) === 1 ? $state : null;
                $context['driver_code'] = (int) ($cause->errorInfo[1] ?? 0);
                break;
            }
            $cause = $cause->getPrevious();
        }

        return $context;
    }
}
