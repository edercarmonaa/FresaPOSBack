<?php

namespace Modules\Sales\Contracts;

use InvalidArgumentException;
use Modules\Sales\Domain\SaleState;
use Modules\Sales\Domain\SalesText;
use Modules\Sales\Domain\SaleType;

final readonly class SaleQuery
{
    public function __construct(
        public ?SaleState $state = null, public ?SaleType $type = null,
        public ?string $tableId = null, public ?string $createdBy = null,
        public int $page = 1, public int $perPage = 25,
        public ?string $date = null, public string $order = 'desc',
    ) {
        if ($page < 1 || $page > 1000000 || ! in_array($perPage, [10, 25, 50, 100], true)) {
            throw new InvalidArgumentException('Paginación no permitida.');
        }
        foreach ([$tableId, $createdBy] as $id) {
            if ($id !== null) {
                SalesText::id($id);
            }
        }
        $parsed = $date === null ? null : \DateTimeImmutable::createFromFormat('!Y-m-d', $date, new \DateTimeZone('UTC'));
        if (! in_array($order, ['asc', 'desc'], true) || ($date !== null && ($parsed === false || $parsed?->format('Y-m-d') !== $date))) {
            throw new InvalidArgumentException('Fecha UTC u orden cronológico no permitido.');
        }
    }
}
