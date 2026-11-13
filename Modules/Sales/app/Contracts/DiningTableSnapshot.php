<?php

namespace Modules\Sales\Contracts;

final readonly class DiningTableSnapshot
{
    public function __construct(public string $id, public string $name, public bool $isActive, public int $displayOrder, public bool $isOccupied, public ?string $activeSaleId = null) {}
}
