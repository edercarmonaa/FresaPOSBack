<?php

namespace Modules\Cash\Contracts;

interface CashClosings
{
    public function pending(string $actorId, string $sessionId, int $page = 1): array;

    public function close(string $actorId, string $sessionId, mixed $countedCash, mixed $observation, string $key): array;

    /** Resultado exclusivamente histórico: nunca precalcula una sesión abierta. */
    public function reconciliation(string $actorId, string $sessionId): array;

    public function history(string $actorId, array $filters = []): array;
}
