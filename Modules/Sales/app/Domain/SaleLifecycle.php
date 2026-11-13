<?php

namespace Modules\Sales\Domain;

final class SaleLifecycle
{
    public static function editable(SaleState $state): void
    {
        if ($state !== SaleState::Open) {
            throw new SalesConflict('Solo se puede editar una venta abierta. Cierre y reapertura requieren Cash.');
        }
    }

    public static function pause(SaleType $type, SaleState $state): void
    {
        self::editable($state);
        if ($type !== SaleType::Counter) {
            throw new SalesConflict('Solo se pausan ventas de mostrador.');
        }
    }

    public static function resume(SaleType $type, SaleState $state): void
    {
        if ($type !== SaleType::Counter || $state !== SaleState::Paused) {
            throw new SalesConflict('Solo se recuperan ventas de mostrador pausadas.');
        }
    }

    public static function cancel(SaleState $state): void
    {
        if (! in_array($state, [SaleState::Open, SaleState::Paused], true)) {
            throw new SalesConflict('No se puede cancelar una venta terminal ni una corrección financiera.');
        }
    }
}
