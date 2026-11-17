<?php

namespace Modules\Cash\Contracts;

interface CashMovements
{
    public function record(string $actorId, string $sessionId, string $type, mixed $amount, mixed $concept, string $key): CashMovementSnapshot;

    public function search(string $actorId, array $filters = []): array;

    public function find(string $actorId, string $id): CashMovementSnapshot;

    public function balance(string $actorId, ?string $sessionId = null): ?array;
}
