<?php

namespace Modules\Cash\Application\Services;

use Modules\Cash\Application\Ports\CashStore;
use Modules\Cash\Contracts\RegisterSnapshot;
use Modules\Core\Contracts\TransactionManager;
use Modules\Users\Contracts\IdentityAccess;

final class ProvisionCashRegister
{
    public function __construct(private readonly TransactionManager $transactions, private readonly IdentityAccess $identities, private readonly CashStore $store) {}

    public function run(): RegisterSnapshot
    {
        return $this->transactions->run(function (): RegisterSnapshot {
            $this->identities->lock();

            return $this->store->provision();
        });
    }
}
