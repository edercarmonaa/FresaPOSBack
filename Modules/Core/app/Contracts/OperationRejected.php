<?php

namespace Modules\Core\Contracts;

final class OperationRejected extends \RuntimeException
{
    public function __construct(public readonly ApiCode $apiCode, public readonly array $errors = [], public readonly ?string $recordId = null)
    {
        parent::__construct((string) __('core::api.'.$apiCode->value));
    }
}
