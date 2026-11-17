<?php

namespace Modules\Cash\Contracts;

/** Puerto de lectura: solo efectivo neto confirmado; requiere transacción externa. */
interface ConfirmedSalesCash
{
    public function total(string $sessionId): string;
}
