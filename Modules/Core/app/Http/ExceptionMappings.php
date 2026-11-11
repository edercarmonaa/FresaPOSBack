<?php

namespace Modules\Core\Http;

use InvalidArgumentException;
use Modules\Core\Contracts\ApiCode;
use Modules\Core\Contracts\ApiExceptionMappings;
use Throwable;

final class ExceptionMappings implements ApiExceptionMappings
{
    /** @var array<class-string<Throwable>, ApiCode> */
    private array $mappings = [];

    public function register(string $exception, ApiCode $code): void
    {
        if ($code->status() < 400) {
            throw new InvalidArgumentException('El mapeo requiere una excepción y un código de error.');
        }
        $this->mappings[$exception] = $code;
    }

    public function codeFor(Throwable $exception): ?ApiCode
    {
        // La clase más específica gana, independientemente del orden de registro.
        foreach ([$exception::class, ...array_values(class_parents($exception))] as $class) {
            if (isset($this->mappings[$class])) {
                return $this->mappings[$class];
            }
        }

        return null;
    }
}
