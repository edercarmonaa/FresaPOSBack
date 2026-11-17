<?php

namespace Modules\Cash\Contracts;

final readonly class RegisterSnapshot
{
    public function __construct(public string $id, public string $name, public bool $isActive) {}
}
