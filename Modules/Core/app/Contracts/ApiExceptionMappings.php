<?php

namespace Modules\Core\Contracts;

use Throwable;

interface ApiExceptionMappings
{
    /** @param class-string<Throwable> $exception */
    public function register(string $exception, ApiCode $code): void;

    public function codeFor(Throwable $exception): ?ApiCode;
}
