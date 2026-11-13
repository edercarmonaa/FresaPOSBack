<?php

namespace Modules\Cash\Contracts;

interface ActiveCashSession
{
    /**
     * Verifica actor, estado abierto y, si se indica, la sesión original de la venta.
     * El adaptador real debe mantener el lock de Cash hasta el commit de la misma
     * transacción/conexión Core, coordinado con su cierre. No basta una lectura sin lock.
     * Devuelve el ULID real; lanza una excepción ante ausencia, cierre o acceso inválido.
     */
    public function requireActive(string $actorId, ?string $expectedSessionId = null): string;
}
