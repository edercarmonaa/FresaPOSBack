<?php

namespace Modules\Sales\Application;

use Modules\Sales\Contracts\DiningTableSnapshot;
use Modules\Sales\Contracts\SaleSnapshot;
use Modules\Sales\Domain\SalesConflict;

final class SalesRevision
{
    // Token opaco de contenido, no contador persistido ni rowversion. Incluye líneas
    // e historia; una repetición con el estado anterior falla sin repetir el cambio.
    public static function of(SaleSnapshot|DiningTableSnapshot $snapshot): string
    {
        return hash_hmac('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR), (string) config('app.key'));
    }

    public static function requireCurrent(SaleSnapshot|DiningTableSnapshot $snapshot, ?string $expected): void
    {
        if ($expected === null || ! hash_equals('"'.self::of($snapshot).'"', $expected)) {
            throw new SalesConflict('Actualiza el recurso y envía su revisión vigente mediante If-Match.');
        }
    }
}
