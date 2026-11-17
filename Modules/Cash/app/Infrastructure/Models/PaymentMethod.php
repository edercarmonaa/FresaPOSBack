<?php

namespace Modules\Cash\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

final class PaymentMethod extends Model
{
    use HasUlids;

    protected $table = 'cash_payment_methods';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    protected static function booted(): void
    {
        self::updating(function (self $model): void {
            if ($model->isDirty(['id', 'code'])) {
                throw new \LogicException('La identidad del método de pago es inmutable.');
            }
        });
        self::deleting(fn () => throw new \LogicException('Los métodos financieros se desactivan; no se borran.'));
    }
}
