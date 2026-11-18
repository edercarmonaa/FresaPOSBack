<?php

namespace Modules\Cash\Contracts;

/** Puerto consumidor de Cash; Sales conserva la propiedad de sus consultas. Transacción externa requerida. */
interface SessionSales
{
    /** @return array{items: array, total: int} Solo identificadores operativos, nunca importes. */
    public function pending(string $sessionId, int $page = 1): array;

    /** @return array{closed_sales: int, total_sold: string, payment_methods: array} */
    public function confirmed(string $sessionId): array;
}
