<?php

namespace Modules\Cash\Application\Ports;

use Modules\Cash\Contracts\RegisterSnapshot;
use Modules\Cash\Contracts\SessionSnapshot;

interface CashStore
{
    /** @return list<RegisterSnapshot> */
    public function registers(): array;

    public function register(string $id): RegisterSnapshot;

    public function active(): ?SessionSnapshot;

    public function replay(string $actorId, string $keyHash, string $fingerprint): ?SessionSnapshot;

    public function create(string $actorId, string $registerId, string $fund, string $keyHash, string $fingerprint): SessionSnapshot;

    public function setActive(string $id, bool $active): RegisterSnapshot;

    public function provision(): RegisterSnapshot;
}
