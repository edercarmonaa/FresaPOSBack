<?php

namespace Modules\Sales\Application\Services;

use Modules\Cash\Contracts\ActiveCashSession;
use Modules\Core\Contracts\TransactionManager;
use Modules\Sales\Contracts\CriticalSalesWrites;
use Modules\Sales\Domain\SalesConflict;
use Modules\Sales\Domain\SalesDependencyUnavailable;
use Modules\Sales\Infrastructure\UnavailableCashSession;
use Modules\Sales\Infrastructure\UnavailableCriticalSalesWrites;
use Modules\Users\Contracts\IdentityAccess;

/** Solo presentación de disponibilidad; nunca reemplaza las guardas de escritura. */
final class SalesAvailability
{
    public function __construct(private readonly ActiveCashSession $cash, private readonly CriticalSalesWrites $critical,
        private readonly TransactionManager $transactions, private readonly IdentityAccess $identities) {}

    public function cash(string $actorId, ?string $sessionId = null): bool
    {
        if ($this->cash instanceof UnavailableCashSession) {
            return false;
        }
        try {
            return $this->transactions->run(function () use ($actorId, $sessionId): bool {
                $this->identities->lock();
                $this->cash->requireActive($actorId, $sessionId);

                return true;
            });
        } catch (SalesConflict|SalesDependencyUnavailable) {
            return false;
        }
    }

    public function critical(): bool
    {
        return ! $this->critical instanceof UnavailableCriticalSalesWrites;
    }
}
