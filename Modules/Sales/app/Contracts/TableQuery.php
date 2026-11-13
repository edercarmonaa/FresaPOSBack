<?php

namespace Modules\Sales\Contracts;

use InvalidArgumentException;
use Modules\Sales\Domain\SalesText;

final readonly class TableQuery
{
    public ?string $search;

    public function __construct(?string $search = null, public ?bool $isActive = null, public ?bool $isOccupied = null, public int $page = 1, public int $perPage = 25)
    {
        $this->search = SalesText::normalize($search, 100);
        if ($page < 1 || $page > 1000000 || ! in_array($perPage, [10, 25, 50, 100], true)) {
            throw new InvalidArgumentException('Paginación no permitida.');
        }
    }
}
