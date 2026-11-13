<?php

namespace Tests\Fixtures\Sales;

use Modules\Cash\Contracts\ActiveCashSession;
use Modules\Sales\Domain\SalesConflict;

final class TestCashSession implements ActiveCashSession
{
    public bool $active = true;

    public function __construct(public readonly string $id) {}

    public function requireActive(string $actorId, ?string $expectedSessionId = null): string
    {
        if (! $this->active || ($expectedSessionId !== null && $expectedSessionId !== $this->id)) {
            throw new SalesConflict('Caja de prueba no disponible.');
        }

        return $this->id;
    }
}
