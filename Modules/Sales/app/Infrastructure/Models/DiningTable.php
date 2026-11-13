<?php

namespace Modules\Sales\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * @property string $id
 * @property string $name
 * @property bool $is_active
 * @property int $display_order
 */
class DiningTable extends Model
{
    use HasUlids;

    protected $table = 'sales_tables';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'display_order' => 'integer'];
    }

    /** @return HasMany<Sale, $this> */
    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class, 'table_id');
    }

    protected static function booted(): void
    {
        static::deleting(fn () => throw new LogicException('Las mesas se desactivan; no se borran referencias históricas.'));
    }
}
