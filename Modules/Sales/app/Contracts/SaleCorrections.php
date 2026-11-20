<?php

namespace Modules\Sales\Contracts;

use Closure;

interface SaleCorrections
{
    /** El actor procede de Auth. El callback revalida sesión/token dentro del lock antes del replay. */
    public function write(string $actorId, string $saleId, string $action, array $input, string $key, ?string $revision, ?Closure $access = null): array;

    public function current(string $actorId, string $saleId): array;

    public function history(string $actorId, string $saleId, int $page = 1): array;
}
