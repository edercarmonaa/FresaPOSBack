<?php

namespace Modules\Cash\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class CashReconciliation extends Model
{
    use HasUlids;

    protected $table = 'cash_reconciliations';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['counted_cash' => 'decimal:4', 'expected_cash' => 'decimal:4', 'difference' => 'decimal:4',
            'summary' => 'array', 'confirmed_at' => 'datetime'];
    }

    /** @return BelongsTo<CashSession, $this> */
    public function session(): BelongsTo
    {
        return $this->belongsTo(CashSession::class, 'cash_session_id');
    }

    protected static function booted(): void
    {
        self::updating(fn () => throw new \LogicException('El arqueo confirmado es inmutable.'));
        self::deleting(fn () => throw new \LogicException('No se elimina el arqueo histórico.'));
    }
}
