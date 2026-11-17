<?php

namespace Modules\Sales\Infrastructure\Persistence;

use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Modules\Sales\Domain\SalesConflict;

final class DailyFolios
{
    public function next(CarbonImmutable $closedAt): string
    {
        if (DB::connection()->transactionLevel() < 1) {
            throw new \LogicException('La secuencia requiere la transacción del cierre.');
        }
        $timezone = (string) config('pos.business_timezone');
        if (! in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
            throw new \LogicException('POS_BUSINESS_TIMEZONE debe ser una zona IANA válida.');
        }
        $local = $closedAt->setTimezone($timezone);
        $date = $local->format('Y-m-d');
        $row = DB::table('sales_daily_sequences')->where('business_date', $date)->lock('with (updlock,holdlock)')->first();
        $next = $row === null ? 1 : ((int) $row->last_value + 1);
        if ($next > 9999) {
            throw new SalesConflict('Se alcanzó el límite diario de folios.');
        }
        if ($row === null) {
            DB::table('sales_daily_sequences')->insert(['business_date' => $date, 'last_value' => $next]);
        } else {
            DB::table('sales_daily_sequences')->where('business_date', $date)->update(['last_value' => $next]);
        }

        return $local->format('Ymd').str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }
}
