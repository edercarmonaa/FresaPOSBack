<?php

namespace Modules\Core\Contracts;

final readonly class OperationResult
{
    /** @param array<string,mixed> $data */
    public function __construct(public int $httpStatus, public array $data, public ?string $resourceId = null)
    {
        if ($httpStatus < 200 || $httpStatus > 299) {
            throw new \InvalidArgumentException('Un resultado confirmado debe ser 2xx.');
        }
    }
}
