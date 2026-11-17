<?php

namespace Modules\Sales\Infrastructure\Persistence\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/** Persistencia reservada. Ningún transporte ni servicio operativo crea pagos. */
final class SalePayment extends Model
{
    use HasUlids;

    protected $table = 'sales_payments';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['applied_amount' => 'decimal:4', 'received_cash' => 'decimal:4', 'change_cash' => 'decimal:4', 'confirmed_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        self::updating(fn () => throw new \LogicException('Los pagos preparados no admiten edición financiera arbitraria.'));
        self::deleting(fn () => throw new \LogicException('El historial de pagos no se elimina.'));
    }
}
