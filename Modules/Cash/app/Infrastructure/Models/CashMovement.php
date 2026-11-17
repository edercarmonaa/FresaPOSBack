<?php

namespace Modules\Cash\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

final class CashMovement extends Model
{
    use HasUlids;

    public const UPDATED_AT = null;

    protected $table = 'cash_movements';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['amount' => 'decimal:4', 'occurred_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        self::updating(fn () => throw new \LogicException('Un movimiento confirmado no se edita.'));
        self::deleting(fn () => throw new \LogicException('Un movimiento confirmado no se elimina.'));
    }
}
