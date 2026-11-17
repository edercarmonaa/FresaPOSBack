<?php

namespace Modules\Cash\Contracts;

interface CashOperations
{
    /** @return list<RegisterSnapshot> */
    public function registers(string $actorId): array;

    public function current(string $actorId): ?SessionSnapshot;

    public function open(string $actorId, string $registerId, mixed $openingFund, string $key): SessionSnapshot;

    public function setRegisterActive(string $actorId, string $registerId, bool $active): RegisterSnapshot;
}
