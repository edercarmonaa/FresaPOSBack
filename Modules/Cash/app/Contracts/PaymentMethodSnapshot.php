<?php

namespace Modules\Cash\Contracts;

final readonly class PaymentMethodSnapshot
{
    public function __construct(public string $id, public string $code, public string $name, public bool $isActive, public int $displayOrder) {}
}
