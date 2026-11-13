<?php

namespace Modules\Sales\Infrastructure;

use Modules\Cash\Contracts\ActiveCashSession;
use Modules\Sales\Domain\CashUnavailable;

final class UnavailableCashSession implements ActiveCashSession
{
    public function requireActive(string $actorId, ?string $expectedSessionId = null): string
    {
        throw new CashUnavailable('La operación requiere una implementación funcional de Cash y una sesión de caja abierta.');
    }
}
