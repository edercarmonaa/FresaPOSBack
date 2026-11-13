<?php

namespace Modules\Sales\Infrastructure\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * @property string $id
 * @property string $type
 * @property string $status
 * @property ?string $table_id
 * @property string $created_by
 * @property string $cash_session_id
 * @property ?string $note
 * @property string $subtotal
 * @property string $discount_amount
 * @property string $total
 * @property ?string $folio
 * @property ?Carbon $closed_at
 * @property ?Carbon $cancelled_at
 * @property ?string $cancelled_by
 * @property ?string $cancellation_reason
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property Collection<int, SaleItem> $items
 */
class Sale extends Model
{
    use HasUlids;

    protected $table = 'sales_sales';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['subtotal' => 'decimal:4', 'discount_amount' => 'decimal:4', 'total' => 'decimal:4', 'closed_at' => 'datetime', 'cancelled_at' => 'datetime'];
    }

    /** @return HasMany<SaleItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(SaleItem::class, 'sale_id')->orderBy('position');
    }

    /** @return BelongsTo<DiningTable, $this> */
    public function diningTable(): BelongsTo
    {
        return $this->belongsTo(DiningTable::class, 'table_id');
    }

    protected static function booted(): void
    {
        static::deleting(fn () => throw new LogicException('No se permite eliminar físicamente ventas.'));
    }
}
