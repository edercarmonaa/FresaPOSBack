<?php

namespace Modules\Sales\Application\Services;

use Closure;
use Modules\Core\Contracts\TransactionManager;
use Modules\Sales\Application\SalesRevision;
use Modules\Sales\Contracts\DiningTableSnapshot;
use Modules\Sales\Contracts\SaleSnapshot;
use Modules\Sales\Contracts\SalesOperations;
use Modules\Users\Contracts\IdentityAccess;

/** Precondición atómica sobre los servicios existentes; ninguna regla financiera. */
final class CoordinateSalesWrite
{
    public function __construct(private readonly TransactionManager $transactions, private readonly IdentityAccess $identities, private readonly SalesOperations $sales) {}

    /** @param Closure(SaleSnapshot|DiningTableSnapshot): (SaleSnapshot|DiningTableSnapshot) $operation */
    public function run(string $actorId, string $id, bool $table, ?string $revision, Closure $operation): SaleSnapshot|DiningTableSnapshot
    {
        return $this->transactions->run(function () use ($actorId, $id, $table, $revision, $operation): SaleSnapshot|DiningTableSnapshot {
            // Mismo lock que TODAS las escrituras de ManageSales, antes de leer.
            // Se retiene hasta commit: dos requests no pueden validar el mismo estado
            // y después sobrescribirse. No se depende de precisión de timestamps.
            $this->identities->lock();
            $snapshot = $table ? $this->sales->table($actorId, $id) : $this->sales->sale($actorId, $id);
            SalesRevision::requireCurrent($snapshot, $revision);

            return $operation($snapshot);
        });
    }
}
