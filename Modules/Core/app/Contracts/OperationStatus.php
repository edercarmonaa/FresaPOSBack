<?php

namespace Modules\Core\Contracts;

final readonly class OperationStatus
{
    public function __construct(public string $state, public bool $expired = false, public ?string $operation = null,
        public ?string $resourceId = null, public ?int $httpStatus = null, public ?string $completedAt = null) {}
}
