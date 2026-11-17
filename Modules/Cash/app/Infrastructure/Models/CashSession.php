<?php

namespace Modules\Cash\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/** @property string $id
 * @property string $register_id
 * @property string $opened_by
 * @property string $status
 * @property string $opening_fund
 * @property Carbon $opened_at
 * @property ?Carbon $closed_at
 * @property ?string $closed_by
 * @property string $opening_fingerprint
 * @property CashRegister $register
 */
class CashSession extends Model
{
    use HasUlids;

    protected $table = 'cash_sessions';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['opening_fund' => 'decimal:4', 'opened_at' => 'datetime', 'closed_at' => 'datetime'];
    }

    /** @return BelongsTo<CashRegister, $this> */
    public function register(): BelongsTo
    {
        return $this->belongsTo(CashRegister::class, 'register_id');
    }

    protected static function booted(): void
    {
        static::deleting(fn () => throw new \LogicException('No se eliminan sesiones de caja.'));
    }
}
