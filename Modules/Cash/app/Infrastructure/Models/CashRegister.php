<?php

namespace Modules\Cash\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** @property string $id
 * @property string $name
 * @property bool $is_active
 */
class CashRegister extends Model
{
    use HasUlids;

    protected $table = 'cash_registers';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /** @return HasMany<CashSession, $this> */
    public function sessions(): HasMany
    {
        return $this->hasMany(CashSession::class, 'register_id');
    }

    protected static function booted(): void
    {
        static::deleting(fn () => throw new \LogicException('Las cajas se conservan; utilizar desactivación autorizada.'));
    }
}
